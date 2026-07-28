<?php

namespace App\Console\Commands;

use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Spot;
use App\Services\OpeningHoursParser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImportOsmSpots extends Command
{
    /**
     * @var string
     */
    protected $signature = 'osm:import {--city=cologne} {--only= : Comma-separated category keys to import (default: all)}';

    /**
     * @var string
     */
    protected $description = 'Import cafes, coworking spaces, and libraries from OpenStreetMap via Overpass API';

    public function handle(CaptureMediaCandidate $captureMediaCandidate): int
    {
        // The database column has second precision. Align the cutoff so rows
        // seen during this same second are not immediately retired.
        $refreshStartedAt = CarbonImmutable::now()->startOfSecond();
        $city = $this->option('city');

        if ($city !== 'cologne') {
            $this->error("City \"{$city}\" is not supported yet. Only \"cologne\" is available.");

            return self::FAILURE;
        }

        $officialVeedels = collect(config('veedels'))->flatten()->all();
        if (DB::table('veedels')->whereIn('name', $officialVeedels)->whereNotNull('boundary')->count() !== 86) {
            $this->error('Official Veedel polygons are required. Run veedels:import first.');

            return self::FAILURE;
        }

        $this->info('Querying Overpass API for Cologne spots...');

        // Fetch each category separately to avoid timeouts. v2: physical
        // leisure across the whole city is the primary content; indoor
        // categories stay on the old inner-city bbox.
        $bbox = '50.83,6.77,51.09,7.16'; // all of Cologne
        $innerBbox = '50.92,6.92,50.96,6.97'; // inner city (cafés etc.)
        $queries = [
            'park' => "[out:json][timeout:40];nwr[\"leisure\"=\"park\"][\"name\"]({$bbox});out center;",
            // No `out` limit on playground/pitch: Cologne has ~2,300 playgrounds
            // and ~2,000 sport pitches, so a cap (was 400/600) silently dropped
            // most of them — whole neighbourhoods went missing. Overpass returns
            // the full set fine within the bumped timeout.
            'playground' => "[out:json][timeout:60];nwr[\"leisure\"=\"playground\"]({$bbox});out center;",
            'pitch' => "[out:json][timeout:60];nwr[\"leisure\"=\"pitch\"][\"sport\"~\"soccer|basketball|tennis|table_tennis|boules|skateboard|multi\"]({$bbox});out center;",
            // Named complexes are destinations in their own right. Their
            // contained courts/pitches are attached by parks:import-areas,
            // never by a proximity guess.
            'sports_centre' => "[out:json][timeout:60];nwr[\"leisure\"=\"sports_centre\"][\"name\"][\"sport\"~\"soccer|football|basketball|tennis|table_tennis|multi\"]({$bbox});out center;",
            'dog_park' => "[out:json][timeout:40];nwr[\"leisure\"=\"dog_park\"]({$bbox});out center;",
            'bbq' => "[out:json][timeout:40];nwr[\"amenity\"=\"bbq\"]({$bbox});out center;",
            // Picnic spots: tables (often inside parks → activity chips) and
            // named picnic sites. Previously not imported at all.
            'picnic' => "[out:json][timeout:40];(nwr[\"leisure\"=\"picnic_table\"]({$bbox});nwr[\"tourism\"=\"picnic_site\"]({$bbox}););out center;",
            'viewpoint' => "[out:json][timeout:40];nwr[\"tourism\"=\"viewpoint\"][\"name\"]({$bbox});out center;",
            'swimming' => "[out:json][timeout:40];(nwr[\"leisure\"=\"swimming_area\"]({$bbox});nwr[\"leisure\"=\"sports_centre\"][\"sport\"=\"swimming\"]({$bbox}););out center;",
            'museum' => "[out:json][timeout:40];nwr[\"tourism\"=\"museum\"][\"name\"]({$bbox});out center;",
            'gallery' => "[out:json][timeout:40];nwr[\"tourism\"=\"gallery\"][\"name\"]({$bbox});out center;",
            'attraction' => "[out:json][timeout:40];nwr[\"tourism\"=\"attraction\"][\"name\"]({$bbox});out center 200;",
            'zoo' => "[out:json][timeout:40];nwr[\"tourism\"=\"zoo\"][\"name\"]({$bbox});out center;",
            'cafe' => "[out:json][timeout:25];node[\"amenity\"=\"cafe\"]({$innerBbox});out body;",
            'coworking' => "[out:json][timeout:25];(node[\"amenity\"=\"coworking_space\"]({$innerBbox});node[\"office\"=\"coworking\"]({$innerBbox}););out body;",
            'library' => "[out:json][timeout:25];node[\"amenity\"=\"library\"]({$innerBbox});out body;",
        ];

        // Optionally re-import a subset (e.g. after a query fix) without
        // re-fetching everything: --only=pitch,playground,picnic
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        if ($only !== []) {
            $queries = array_intersect_key($queries, array_flip($only));
            if ($queries === []) {
                $this->error('No matching categories for --only='.implode(',', $only));

                return self::FAILURE;
            }
        }

        // Mirrors in preference order — the big city-wide leisure queries
        // get rate-limited on a single endpoint, so fall through on failure.
        $mirrors = [
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ];

        $allElements = [];
        $refreshedGroups = [];
        foreach ($queries as $category => $query) {
            $this->info("  Fetching {$category}...");
            $fetched = false;

            foreach ($mirrors as $mirror) {
                try {
                    $response = Http::timeout(90)->get($mirror, ['data' => $query]);

                    if ($response->successful()) {
                        $payload = $response->json();
                        if (! is_array($payload) || isset($payload['remark']) || ! array_key_exists('elements', $payload) || ! is_array($payload['elements'])) {
                            $this->warn("    {$category} via {$mirror}: invalid Overpass payload");

                            continue;
                        }
                        $elements = $payload['elements'];
                        foreach ($elements as &$el) {
                            $el['_category'] = $category;
                        }
                        $allElements = array_merge($allElements, $elements);
                        $refreshedGroups[] = $category;
                        $this->info('    Found '.count($elements)." {$category} spots");
                        $fetched = true;
                        break;
                    }

                    $this->warn("    {$category} via {$mirror}: status {$response->status()}");
                } catch (\Exception $e) {
                    $this->warn("    {$category} via {$mirror}: {$e->getMessage()}");
                }
            }

            if (! $fetched) {
                $this->warn("    {$category}: all mirrors failed, skipping");
            }

            sleep(2); // Rate limit courtesy
        }

        if ($refreshedGroups === []) {
            $this->error('No spots found from Overpass API.');

            return self::FAILURE;
        }

        $this->info('Total: '.count($allElements).' spots from Overpass');

        try {
            // Fake response structure for existing code below
            $response = new class($allElements)
            {
                public function __construct(private array $elements) {}

                public function json(string $key = '', $default = null)
                {
                    return $key === 'elements' ? $this->elements : $default;
                }

                public function successful(): bool
                {
                    return true;
                }
            };
        } catch (\Exception $e) {
            $this->error("Overpass API request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $elements = $response->json('elements', []);

        $this->info('  Received '.count($elements).' elements from Overpass');

        $bar = $this->output->createProgressBar(count($elements));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');

        $imported = 0;
        $skippedNoName = 0;
        $skippedDuplicate = 0;
        $skippedOutside = 0;

        foreach ($elements as $element) {
            $bar->advance();

            $tags = $element['tags'] ?? [];

            // Determine category from tags (query category as the hint)
            $category = $this->resolveCategory($tags, $element['_category'] ?? 'cafe');

            $name = $tags['name'] ?? $this->fallbackName($category, $tags);

            // Skip elements without a usable name
            if (! $name) {
                $skippedNoName++;

                continue;
            }

            // Ways/relations carry their coordinate in `center`
            $lat = (float) ($element['lat'] ?? $element['center']['lat'] ?? 0);
            $lng = (float) ($element['lon'] ?? $element['center']['lon'] ?? 0);
            if (! $lat || ! $lng) {
                $skippedNoName++;

                continue;
            }

            $veedel = $this->veedelContaining($lat, $lng);
            if ($veedel === null) {
                $skippedOutside++;

                continue;
            }

            $keptTags = $this->keptTags($tags);

            // Build address from OSM tags
            $address = $this->buildAddress($tags);

            $sourceId = ($element['type'] ?? 'node').'/'.$element['id'];
            $existing = Spot::query()->where('source', 'osm')->where('source_id', $sourceId)->first();
            $values = [
                'name' => $name,
                'category' => $category,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'veedel' => $veedel,
                'tags' => $keptTags ?: null,
                'opening_hours' => OpeningHoursParser::parse($tags['opening_hours'] ?? null),
                'source_group' => $element['_category'],
                'last_seen_at' => now(),
                'is_active' => true,
                'is_recommendable' => $this->isRecommendationDestination($category, $name),
            ];

            if ($existing !== null) {
                $existing->update(['source' => 'osm', 'source_id' => $sourceId, ...$values]);
                $spot = $existing;
            } else {
                $spot = Spot::query()->create(['source' => 'osm', 'source_id' => $sourceId, ...$values]);
            }

            $this->captureSourceMedia($spot, $tags, $sourceId, $captureMediaCandidate);

            // PostGIS `location` is synced by Spot's saved() hook.
            $existing ? $skippedDuplicate++ : $imported++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Import complete:');
        $this->info("  Imported: {$imported}");
        $this->info("  Skipped (no name): {$skippedNoName}");
        $this->info("  Skipped (duplicate): {$skippedDuplicate}");
        $this->info("  Skipped (outside Cologne): {$skippedOutside}");

        // A successful category refresh is also an authoritative deletion
        // signal. Rows absent from this run remain for audit/history but stop
        // competing in discovery and Composer immediately.
        Spot::query()
            ->where('source', 'osm')
            ->whereIn('source_group', array_unique($refreshedGroups))
            ->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $refreshStartedAt))
            ->update(['is_active' => false, 'is_recommendable' => false]);

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $tags */
    private function captureSourceMedia(
        Spot $spot,
        array $tags,
        string $sourceId,
        CaptureMediaCandidate $captureMediaCandidate,
    ): void {
        $sourcePageUrl = 'https://www.openstreetmap.org/'.$sourceId;
        $commonsReference = trim((string) ($tags['wikimedia_commons'] ?? ''));
        $hasCommonsFile = preg_match('/^File:(?<filename>.+)$/iu', $commonsReference, $match) === 1;

        try {
            if ($hasCommonsFile) {
                $filename = str_replace(' ', '_', trim($match['filename']));
                $providerAssetId = 'File:'.$filename;
                $encodedFilename = str_replace('%2F', '/', rawurlencode($filename));
                $encodedProviderId = str_replace(['%3A', '%2F'], [':', '/'], rawurlencode($providerAssetId));

                $captureMediaCandidate->execute($spot, new MediaCandidate(
                    provider: 'wikimedia-commons',
                    remoteUrl: 'https://commons.wikimedia.org/wiki/Special:FilePath/'.$encodedFilename,
                    providerAssetId: $providerAssetId,
                    sourcePageUrl: 'https://commons.wikimedia.org/wiki/'.$encodedProviderId,
                    role: 'hero',
                    priority: 20,
                    isPrimary: true,
                    metadata: ['discovered_via' => $sourcePageUrl],
                    shouldValidate: false,
                ));
            }

            $sourceImage = preg_replace('/^http:\/\//i', 'https://', trim((string) ($tags['image'] ?? '')));
            if ($sourceImage !== '') {
                $captureMediaCandidate->execute($spot, new MediaCandidate(
                    provider: 'osm-image',
                    remoteUrl: $sourceImage,
                    sourcePageUrl: $sourcePageUrl,
                    role: 'hero',
                    priority: 30,
                    isPrimary: ! $hasCommonsFile,
                    metadata: ['discovered_via' => $sourcePageUrl],
                    shouldValidate: false,
                ));
            }
        } catch (Throwable $exception) {
            Log::warning('OSM source media candidate was skipped', [
                'spot_id' => $spot->id,
                'source_id' => $sourceId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function veedelContaining(float $lat, float $lng): ?string
    {
        $row = DB::selectOne(
            'SELECT name FROM veedels WHERE boundary IS NOT NULL
             AND ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)) LIMIT 1',
            [$lng, $lat],
        );

        return $row->name ?? null;
    }

    private function isRecommendationDestination(string $category, string $name): bool
    {
        $microfacilities = [
            'playground', 'pitch', 'basketball', 'tennis', 'table_tennis',
            'boules', 'dog_park', 'bbq', 'picnic', 'skatepark',
        ];

        if (! in_array($category, $microfacilities, true)) {
            return true;
        }

        $genericLabels = array_map(
            fn (string $label): string => preg_quote($label, '/'),
            array_values(self::FALLBACK_LABELS),
        );

        return preg_match('/^('.implode('|', $genericLabels).')(?:\s*·.*)?$/iu', trim($name)) !== 1;
    }

    /**
     * OSM tags worth keeping — they feed the place detail's facts/chips;
     * wikidata/wikipedia link to Commons photos (spots:fetch-photos).
     */
    private const KEPT_TAG_KEYS = [
        'surface', 'lit', 'covered', 'indoor', 'access', 'fee', 'opening_hours',
        'sport', 'hoops', 'wheelchair', 'drinking_water', 'barrier',
        // Photo links resolved later by spots:fetch-photos. wikimedia_commons
        // and image are mapper-provided photos of the place itself.
        'wikidata', 'wikipedia', 'wikimedia_commons', 'image',
    ];

    /**
     * @param  array<string, string>  $tags
     * @return array<string, string>
     */
    protected function keptTags(array $tags): array
    {
        return array_intersect_key($tags, array_flip(self::KEPT_TAG_KEYS));
    }

    /**
     * Resolve the spot category from OSM tags, refining pitches by sport.
     *
     * @param  array<string, string>  $tags
     */
    protected function resolveCategory(array $tags, string $hint): string
    {
        $amenity = $tags['amenity'] ?? '';
        $office = $tags['office'] ?? '';

        if ($amenity === 'coworking_space' || $office === 'coworking') {
            return 'coworking';
        }
        if ($amenity === 'library') {
            return 'library';
        }

        // The attraction query also returns museums/galleries/zoos —
        // prefer the specific tourism tag over the query hint.
        $tourism = $tags['tourism'] ?? '';
        if (in_array($tourism, ['museum', 'gallery', 'zoo'], true)) {
            return $tourism;
        }

        if ($hint === 'pitch') {
            return match (true) {
                str_contains($tags['sport'] ?? '', 'basketball') => 'basketball',
                str_contains($tags['sport'] ?? '', 'tennis') && ! str_contains($tags['sport'] ?? '', 'table') => 'tennis',
                str_contains($tags['sport'] ?? '', 'table_tennis') => 'table_tennis',
                str_contains($tags['sport'] ?? '', 'boules') => 'boules',
                str_contains($tags['sport'] ?? '', 'skateboard') => 'skatepark',
                default => 'pitch',
            };
        }

        if ($hint === 'sports_centre') {
            return 'sports_centre';
        }

        return $hint;
    }

    /**
     * The German type word each unnamed category falls back to. Public so the
     * `spots:reveal-names` backfill can recognise these bare labels and anchor
     * them to a park / street (they duplicate heavily on their own).
     *
     * @var array<string, string>
     */
    public const FALLBACK_LABELS = [
        'playground' => 'Spielplatz',
        'pitch' => 'Bolzplatz',
        'basketball' => 'Basketballplatz',
        'tennis' => 'Tennisplatz',
        'table_tennis' => 'Tischtennisplatte',
        'boules' => 'Boulebahn',
        'skatepark' => 'Skatepark',
        'dog_park' => 'Hundewiese',
        'bbq' => 'Grillplatz',
        'picnic' => 'Picknickplatz',
    ];

    /**
     * Unnamed playgrounds and pitches are the norm in OSM; synthesise a usable
     * name from the category + street. Park containment isn't known yet at
     * import (parks:import-areas runs later), so `spots:reveal-names` folds the
     * park/street anchor in afterwards.
     *
     * @param  array<string, string>  $tags
     */
    protected function fallbackName(string $category, array $tags): ?string
    {
        $label = self::FALLBACK_LABELS[$category] ?? null;

        if ($label === null) {
            return null;
        }

        $street = $tags['addr:street'] ?? null;

        return $street ? "{$label} · {$street}" : $label;
    }

    /**
     * Build a human-readable address from OSM address tags.
     *
     * @param  array<string, string>  $tags
     */
    protected function buildAddress(array $tags): ?string
    {
        $parts = [];

        $street = $tags['addr:street'] ?? null;
        $houseNumber = $tags['addr:housenumber'] ?? null;

        if ($street) {
            $parts[] = $houseNumber ? "{$street} {$houseNumber}" : $street;
        }

        $city = $tags['addr:city'] ?? null;
        if ($city) {
            $parts[] = $city;
        }

        return $parts ? implode(', ', $parts) : null;
    }
}
