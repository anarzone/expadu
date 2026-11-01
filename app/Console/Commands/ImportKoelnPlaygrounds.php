<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Names and equipment of the city's own playgrounds and ball courts, from
 * Stadt Köln's "Spiel- und Sportplätze in Köln" (Amt für Kinder, Jugend und
 * Familie; Datenlizenz Deutschland Zero 2.0, no attribution required). Each
 * city entry is matched one to one with the nearest OSM playground or pitch
 * within 80 m and recorded as provider "stadt-koeln": its official name (used
 * where OSM only says "Spielplatz"), public access, and the sports and surface
 * its equipment lists, the latter only where OSM says nothing, so they never
 * contradict it. Data only: the dataset's photos are not used.
 */
#[Signature('places:import-koeln-playgrounds {--dry-run}')]
#[Description('Name and equip city playgrounds and ball courts from Stadt Köln open data')]
class ImportKoelnPlaygrounds extends Command
{
    private const LAYER = 'https://geoportal.stadt-koeln.de/arcgis/rest/services/freizeit_natur_sport/spielangebote/MapServer/0/query';

    private const DATASET = 'https://www.offenedaten-koeln.de/dataset/spiel-und-sportplatze-in-koln';

    private const KINDS = ['playground', 'pitch', 'table_tennis', 'basketball', 'boules', 'skatepark'];

    private const SURFACES = ['wiese' => 'grass', 'rasen' => 'grass', 'kunstrasen' => 'artificial_turf', 'asche' => 'clay', 'asphalt' => 'asphalt', 'tartan' => 'tartan', 'hartplatz' => 'paved', 'pflaster' => 'paving_stones', 'sand' => 'sand'];

    public function handle(RecordPlaceObservation $record, PlaceFacts $facts): int
    {
        $response = Http::timeout(120)->withUserAgent(ImportOsmSpots::USER_AGENT)->get(self::LAYER, [
            'where' => '1=1', 'outFields' => '*', 'outSR' => 4326, 'f' => 'json',
        ]);
        $features = collect($response->json('features', []))->filter(fn (array $f): bool => isset($f['geometry']['x'], $f['geometry']['y']) && trim((string) ($f['attributes']['spielplatzname'] ?? '')) !== '');
        if (! $response->successful() || $features->isEmpty()) {
            $this->error('The city playground layer returned nothing usable.');

            return self::FAILURE;
        }

        // Nearest feature per OSM place, so each place takes one city entry.
        $pairs = [];
        foreach ($features as $feature) {
            $a = $feature['attributes'];
            [$lat, $lng] = [(float) $feature['geometry']['y'], (float) $feature['geometry']['x']];
            $preferred = in_array($a['typ'] ?? '', ['Bolzplatz', 'Sportanlage'], true) ? ['pitch'] : ['playground'];
            $near = Spot::query()->where('is_active', true)->whereIn('category', self::KINDS)
                ->whereBetween('lat', [$lat - 0.00075, $lat + 0.00075])->whereBetween('lng', [$lng - 0.0012, $lng + 0.0012])
                ->get(['id', 'lat', 'lng', 'category'])
                ->map(fn (Spot $spot): array => [$spot, $this->metres($lat, $lng, (float) $spot->lat, (float) $spot->lng)])
                ->filter(fn (array $pair): bool => $pair[1] <= 80)
                ->sortBy(fn (array $pair): array => [in_array($pair[0]->category->value, $preferred, true) ? 0 : 1, $pair[1]])
                ->first();
            if ($near !== null && (! isset($pairs[$near[0]->id]) || $pairs[$near[0]->id]['distance'] > $near[1])) {
                $pairs[$near[0]->id] = ['spot' => $near[0], 'feature' => $a, 'distance' => $near[1]];
            }
        }

        $stats = ['city_entries' => $features->count(), 'matched' => count($pairs), 'recorded' => 0, 'with_sport' => 0, 'now_suggestible' => 0, 'failed' => 0];
        $observedAt = CarbonImmutable::now()->startOfSecond();
        foreach ($pairs as $pair) {
            $spot = Spot::query()->find($pair['spot']->id);
            $a = $pair['feature'];
            $current = $facts->resolve($spot);
            $payload = ['name' => $this->name($a)];
            if (($current['access']['status'] ?? 'unknown') === 'unknown') {
                $payload['access'] = ['raw' => 'yes']; // the city's register of its public play areas
            }
            $practical = [];
            if (($current['practical']['sport']['status'] ?? 'unknown') === 'unknown' && ($sport = $this->sports($a)) !== null) {
                $practical['sport'] = $sport;
            }
            if (($current['practical']['surface']['status'] ?? 'unknown') === 'unknown' && ($surface = $this->surface($a)) !== null) {
                $practical['surface'] = $surface;
            }
            if ($practical !== []) {
                $payload['practical'] = $practical;
            }
            $id = trim((string) ($a['spielplatz_nr'] ?? '')) !== '' ? trim((string) $a['spielplatz_nr']) : 'objectid-'.$a['objectid'];
            $stats['with_sport'] += isset($practical['sport']) ? 1 : 0;

            if ($this->option('dry-run')) {
                $stats['recorded']++;

                continue;
            }
            try {
                $record->record($spot, [
                    'provider' => 'stadt-koeln',
                    'provider_record_id' => mb_substr($id, 0, 191),
                    'source_url' => self::DATASET,
                    'observed_at' => $observedAt->toIso8601String(),
                    'ingestion_key' => 'stadt-koeln:spielangebote:'.mb_substr($id, 0, 120),
                    'payload' => $payload,
                ]);
                $stats['recorded']++;
                // Named and public now: a place people can be offered (as the OSM import decides).
                $resolved = $facts->resolve($spot->fresh());
                $public = ($resolved['access']['status'] ?? null) === 'known' && ! in_array($resolved['access']['value'] ?? 'unknown', ['private', 'no', 'customers', 'members', 'permit'], true);
                if (! $spot->is_recommendable && $public && ! preg_match('/^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Boulebahn|Skatepark)(\s*·.*)?$/iu', (string) $resolved['name']['value'])) {
                    $spot->forceFill(['is_recommendable' => true])->save();
                    $stats['now_suggestible']++;
                }
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->warn("  spot {$spot->id}: {$e->getMessage()}");
            }
        }

        $this->table(array_keys($stats), [array_values($stats)]);

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** "Spielplatz Marienplatz/Mühlenbach", "Bolzplatz Vorgebirgspark": the kind and the city's name. */
    private function name(array $a): string
    {
        $kind = match ($a['typ'] ?? null) {
            'Bolzplatz' => 'Bolzplatz',
            'Sportanlage' => 'Sportanlage',
            default => 'Spielplatz',
        };

        return mb_substr($kind.' '.trim((string) $a['spielplatzname']), 0, 255);
    }

    private function sports(array $a): ?string
    {
        $has = fn (string $key): bool => ($value = trim((string) ($a[$key] ?? ''))) !== '' && ! in_array(mb_strtolower($value), ['0', 'nein', 'kein', 'keine'], true);
        $sports = array_keys(array_filter([
            'soccer' => $has('fussballtore') || $has('torwand'),
            'basketball' => $has('basketballkoerbe'),
            'table_tennis' => $has('tischtennisplatten'),
            'boules' => $has('boulebahn'),
            'skateboard' => $has('skaterelemente'),
        ]));

        return $sports === [] ? null : implode(';', $sports);
    }

    private function surface(array $a): ?string
    {
        $value = mb_strtolower(trim((string) ($a['platzbelag_fussball'] ?? '')));
        foreach (self::SURFACES as $word => $osm) {
            if ($value !== '' && str_contains($value, $word)) {
                return $osm;
            }
        }

        return null;
    }

    private function metres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        return hypot(($lat1 - $lat2) * 111_320, ($lng1 - $lng2) * 111_320 * cos(deg2rad($lat1)));
    }
}
