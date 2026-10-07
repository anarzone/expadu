<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Spot;
use App\Places\PlaceCapabilities;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Services\OpeningHoursParser;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    public function handle(
        CaptureMediaCandidate $captureMediaCandidate,
        RecordPlaceObservation $recordPlaceObservation,
        PlaceFacts $placeFacts,
    ): int {
        // The database column has second precision. Align the cutoff so rows
        // seen during this same second are not immediately retired.
        $refreshStartedAt = CarbonImmutable::now()->startOfSecond();
        $observationRunId = (string) Str::uuid();
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

        // Derive acquisition bounds from the same official polygons used
        // for acceptance; a hand-written rectangle can omit edge locations.
        $bounds = DB::table('veedels')
            ->whereIn('name', $officialVeedels)
            ->whereNotNull('boundary')
            ->selectRaw('ST_YMin(ST_Extent(boundary)) AS south, ST_XMin(ST_Extent(boundary)) AS west, ST_YMax(ST_Extent(boundary)) AS north, ST_XMax(ST_Extent(boundary)) AS east')
            ->first();
        $coordinates = [$bounds?->south, $bounds?->west, $bounds?->north, $bounds?->east];
        if (in_array(null, $coordinates, true)) {
            $this->error('Official Veedel polygons must have a usable geographic extent.');

            return self::FAILURE;
        }
        $bbox = implode(',', $coordinates);
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
            'attraction' => "[out:json][timeout:40];nwr[\"tourism\"=\"attraction\"][\"name\"]({$bbox});out center;",
            'zoo' => "[out:json][timeout:40];nwr[\"tourism\"=\"zoo\"][\"name\"]({$bbox});out center;",
            'cafe' => "[out:json][timeout:40];nwr[\"amenity\"=\"cafe\"]({$bbox});out center;",
            'restaurant' => "[out:json][timeout:40];nwr[\"amenity\"=\"restaurant\"]({$bbox});out center;",
            'fast_food' => "[out:json][timeout:40];nwr[\"amenity\"=\"fast_food\"]({$bbox});out center;",
            'bar' => "[out:json][timeout:40];nwr[\"amenity\"=\"bar\"]({$bbox});out center;",
            'bakery' => "[out:json][timeout:40];nwr[\"shop\"=\"bakery\"]({$bbox});out center;",
            'coworking' => "[out:json][timeout:25];(nwr[\"amenity\"=\"coworking_space\"]({$bbox});nwr[\"office\"=\"coworking\"]({$bbox}););out center;",
            'library' => "[out:json][timeout:25];nwr[\"amenity\"=\"library\"]({$bbox});out center;",
            // Named lakes, woods, reserves and recreation grounds that people
            // visit but OSM does not tag as parks (Königsforst, Fühlinger See,
            // Poller Wiesen). Bounds let greenSpaceQualifies() skip fountains,
            // basins and tiny ponds.
            'green' => "[out:json][timeout:90];(nwr[\"landuse\"=\"recreation_ground\"][\"name\"]({$bbox});nwr[\"leisure\"=\"nature_reserve\"][\"name\"]({$bbox});wr[\"landuse\"=\"forest\"][\"name\"]({$bbox});wr[\"natural\"=\"wood\"][\"name\"]({$bbox});wr[\"natural\"=\"water\"][\"name\"]({$bbox}););out tags bb;",
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
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
            'https://overpass.kumi.systems/api/interpreter',
        ];

        $allElements = [];
        $refreshedGroups = [];
        foreach ($queries as $category => $query) {
            $this->info("  Fetching {$category}...");
            $fetched = false;

            foreach ($mirrors as $mirror) {
                try {
                    $response = $this->overpass($mirror, $query);

                    if ($response->successful()) {
                        $payload = $response->json();
                        if (! is_array($payload) || isset($payload['remark']) || ! array_key_exists('elements', $payload) || ! is_array($payload['elements'])) {
                            $this->warn("    {$category} via {$mirror}: invalid Overpass payload");

                            continue;
                        }
                        // A lagging mirror would record months-old tags as
                        // observed today, and retire places opened since.
                        $dataAge = $this->dataAgeHours($payload);
                        if ($dataAge === null || $dataAge > self::MAX_SOURCE_AGE_HOURS) {
                            $this->warn("    {$category} via {$mirror}: stale data (".($dataAge === null ? 'no timestamp' : round($dataAge).' h old').')');

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

        $elements = $this->uniqueElements($response->json('elements', []));

        $this->info('  Received '.count($elements).' elements from Overpass');

        $bar = $this->output->createProgressBar(count($elements));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');

        $imported = 0;
        $skippedNoName = 0;
        $skippedDuplicate = 0;
        $skippedOutside = 0;
        $importedGreenNames = [];

        foreach ($elements as $element) {
            $bar->advance();

            $tags = $element['tags'] ?? [];

            if (($element['_category'] ?? null) === 'green' && ! $this->greenSpaceQualifies($element)) {
                $skippedNoName++;

                continue;
            }

            // Determine category from tags (query category as the hint)
            $category = $this->resolveCategory($tags, $element['_category'] ?? 'cafe');

            $name = $tags['name'] ?? $this->fallbackName($category, $tags);

            // Skip elements without a usable name
            if (! $name) {
                $skippedNoName++;

                continue;
            }

            // Ways/relations carry their coordinate in `center`
            [$lat, $lng] = $this->elementPoint($element);
            if (! $lat || ! $lng) {
                $skippedNoName++;

                continue;
            }

            $veedel = $this->veedelContaining($lat, $lng);
            if ($veedel === null) {
                $skippedOutside++;

                continue;
            }

            $greenName = ($element['_category'] ?? null) === 'green' ? mb_strtolower(trim($name)) : null;
            if ($greenName !== null && isset($importedGreenNames[$greenName])) {
                $skippedDuplicate++;

                continue;
            }
            if ($greenName !== null) {
                $importedGreenNames[$greenName] = true;
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
                'source_group' => in_array($category, SpotCategory::finesForCoarse('food_drink'), true) ? $category : $element['_category'],
                'last_seen_at' => now(),
                'is_active' => true,
                'is_recommendable' => $this->isRecommendationDestination($category, $name),
            ];

            $spot = DB::transaction(function () use (
                $existing,
                $values,
                $sourceId,
                $element,
                $tags,
                $lat,
                $lng,
                $address,
                $refreshStartedAt,
                $observationRunId,
                $recordPlaceObservation,
                $placeFacts,
                $category,
                $name,
            ): Spot {
                if ($existing !== null) {
                    $existing->update(['source' => 'osm', 'source_id' => $sourceId, ...$values]);
                    $spot = $existing;
                } else {
                    $spot = Spot::query()->create(['source' => 'osm', 'source_id' => $sourceId, ...$values]);
                }

                $recordPlaceObservation->record($spot, [
                    'provider' => 'osm',
                    'provider_record_id' => $sourceId,
                    'source_url' => 'https://www.openstreetmap.org/'.$sourceId,
                    'observed_at' => $refreshStartedAt,
                    'ingestion_key' => "osm:{$observationRunId}:{$sourceId}",
                    'payload' => $this->observationPayload($element, $tags, $sourceId, $lat, $lng, $address),
                ]);

                $facts = $placeFacts->resolve($spot->fresh());
                $resolvedName = $facts['name']['value'] ?? $name;
                $spot->update([
                    'name' => $resolvedName,
                    'address' => $facts['contact']['address']['value'],
                    'opening_hours' => $facts['hours']['parsed'],
                    'website' => $facts['contact']['website']['value'],
                    'phone' => $facts['contact']['phone']['value'],
                    'description' => $facts['description']['value'],
                    'is_recommendable' => $this->isRecommendationDestination($category, $resolvedName)
                        && $this->hasPublicRecommendationAccess($facts['access']),
                ]);

                return $spot;
            });

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
                    matchStatus: 'accepted',
                    matchMethod: 'osm_wikimedia_commons_tag',
                    matchEvidence: [
                        'source' => 'osm',
                        'source_id' => $sourceId,
                        'tag' => $commonsReference,
                        'commons_file' => $filename,
                    ],
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
                    matchMethod: 'osm_image_tag',
                    matchEvidence: [
                        'source' => 'osm',
                        'source_id' => $sourceId,
                        'tag' => $sourceImage,
                    ],
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
        // Source names that announce a closure or a back office are kept for
        // identity but never suggested as somewhere to go.
        if (preg_match('/\b(geschlossen|closed|dauerhaft geschlossen)\b|\((verwaltung|administration)\)/iu', $name) === 1) {
            return false;
        }

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
     * Keep valid source facts so refreshes retain practical details and conditions.
     *
     * @param  array<string, mixed>  $tags
     * @return array<string, string>
     */
    protected function keptTags(array $tags): array
    {
        return array_filter(
            $tags,
            static fn (mixed $value, mixed $key): bool => is_string($key)
                && $key !== ''
                && is_string($value)
                && trim($value) !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Preserve what the provider actually said. Display projections are
     * resolved separately so a later refresh cannot erase reviewed facts.
     *
     * @param  array<string, mixed>  $element
     * @param  array<string, mixed>  $tags
     * @return array<string, mixed>
     */
    private function observationPayload(
        array $element,
        array $tags,
        string $sourceId,
        float $lat,
        float $lng,
        ?string $address,
    ): array {
        $type = (string) ($element['type'] ?? 'node');

        return [
            'name' => $this->tagValue($tags['name'] ?? null, 255),
            'aliases' => $this->sourceAliases($tags),
            'location' => [
                'lat' => $lat,
                'lng' => $lng,
                'kind' => $type === 'node' ? 'source_node' : 'source_center',
                'boundary_reference' => $type === 'node' ? null : $sourceId,
            ],
            'access' => [
                'raw' => $this->tagValue($tags['access'] ?? null, 500),
                'conditional' => $this->tagValue($tags['access:conditional'] ?? null, 500),
            ],
            'fee' => [
                'raw' => $this->tagValue($tags['fee'] ?? null, 255),
                'conditional' => $this->tagValue($tags['fee:conditional'] ?? null, 500),
                'charge' => $this->tagValue($tags['charge'] ?? null, 500),
                'charge_conditional' => $this->tagValue($tags['charge:conditional'] ?? null, 500),
            ],
            'practical' => PlaceCapabilities::sourceTags($tags),
            'hours' => ['raw' => $this->tagValue($tags['opening_hours'] ?? null, 500)],
            'contact' => [
                'website' => $this->httpUrlTag($tags['contact:website'] ?? $tags['website'] ?? null),
                'phone' => $this->tagValue($tags['contact:phone'] ?? $tags['phone'] ?? null, 500),
                'address' => $this->tagValue($address, 500),
            ],
            'description' => $this->tagValue($tags['description'] ?? $tags['description:en'] ?? null, 1000),
            'negative_facts' => collect(['covered', 'drinking_water', 'indoor', 'lit', 'wheelchair'])
                ->filter(fn (string $key): bool => in_array(mb_strtolower((string) ($tags[$key] ?? '')), ['no', 'false', '0'], true))
                ->mapWithKeys(fn (string $key): array => [$key => (string) $tags[$key]])
                ->all(),
        ];
    }

    /** @param array<string, mixed> $tags
     * @return list<string>
     */
    private function sourceAliases(array $tags): array
    {
        $aliases = [];
        foreach (['alt_name', 'short_name', 'official_name', 'local_name', 'old_name'] as $key) {
            $value = $this->tagValue($tags[$key] ?? null);
            if ($value !== null) {
                array_push($aliases, ...array_map('trim', explode(';', $value)));
            }
        }

        $localized = collect($tags)
            ->filter(fn (mixed $value, string $key): bool => preg_match('/^name:[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $key) === 1 && $this->tagValue($value) !== null)
            ->sortKeys()
            ->values()
            ->map(fn (mixed $value): ?string => $this->tagValue($value))
            ->filter()
            ->all();

        return collect([...$aliases, ...$localized])
            ->map(fn (string $alias): string => trim($alias))
            ->filter(fn (string $alias): bool => $alias !== '' && mb_strlen($alias) <= 255 && $alias !== $this->tagValue($tags['name'] ?? null))
            ->unique()
            ->values()
            ->take(50)
            ->all();
    }

    /** @param array<string, mixed> $access */
    private function hasPublicRecommendationAccess(array $access): bool
    {
        return $access['status'] !== 'conflicting'
            && ($access['conditional'] ?? null) === null
            && ! in_array($access['value'] ?? 'unknown', ['private', 'no', 'customers', 'members', 'permit'], true);
    }

    private function tagValue(mixed $value, ?int $maxLength = null): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' || ($maxLength !== null && mb_strlen($value) > $maxLength) ? null : $value;
    }

    private function httpUrlTag(mixed $value): ?string
    {
        $url = $this->tagValue($value, 500);
        if ($url === null) {
            return null;
        }

        if (preg_match('~^(https?)://([^/?#]+)(.*)$~iuD', $url, $parts) !== 1
            || preg_match('~^(\[[0-9a-f:.]+\]|[^:@]+)(:\d+)?$~iuD', $parts[2], $authority) !== 1) {
            return null;
        }
        $host = str_starts_with($authority[1], '[')
            ? $authority[1]
            : idn_to_ascii($authority[1], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($host === false) {
            return null;
        }
        $suffix = preg_replace_callback('/[^\x21-\x7e]+/u', static fn (array $match): string => rawurlencode($match[0]), $parts[3]);
        $url = $suffix === null ? null : mb_strtolower($parts[1]).'://'.$host.($authority[2] ?? '').$suffix;

        return $url !== null && mb_strlen($url) <= 500 && filter_var($url, FILTER_VALIDATE_URL) !== false
            ? $url
            : null;
    }

    /**
     * Resolve the spot category from OSM tags, refining pitches by sport.
     *
     * @param  array<string, string>  $tags
     */
    protected function resolveCategory(array $tags, string $hint): string
    {
        if ($hint === 'green') {
            return match (true) {
                ($tags['natural'] ?? null) === 'water' => 'lake',
                ($tags['landuse'] ?? null) === 'recreation_ground' => 'park',
                default => 'nature',
            };
        }

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

        if (in_array($hint, SpotCategory::finesForCoarse('food_drink'), true)) {
            if (in_array($amenity, ['cafe', 'restaurant', 'fast_food', 'bar'], true)) {
                return $amenity;
            }
            if (($tags['shop'] ?? '') === 'bakery') {
                return 'bakery';
            }
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
    /**
     * Nodes carry lat/lon and ways `center`. Green-space queries request
     * bounds for the size filter, so Overpass omits `center`; use the midpoint.
     *
     * @param  array<string, mixed>  $element
     * @return array{0: float, 1: float}
     */
    protected function elementPoint(array $element): array
    {
        $bounds = $element['bounds'] ?? null;
        $fromBounds = fn (string $axis): float => is_array($bounds) && isset($bounds["min{$axis}"], $bounds["max{$axis}"])
            ? ((float) $bounds["min{$axis}"] + (float) $bounds["max{$axis}"]) / 2
            : 0.0;

        return [
            (float) ($element['lat'] ?? $element['center']['lat'] ?? $fromBounds('lat')),
            (float) ($element['lon'] ?? $element['center']['lon'] ?? $fromBounds('lon')),
        ];
    }

    /**
     * One OSM object can match several category queries, and each query may be
     * answered by a mirror with different replication state. Record it once per
     * run, keeping the first query's category hint.
     *
     * @param  list<array<string, mixed>>  $elements
     * @return list<array<string, mixed>>
     */
    protected function uniqueElements(array $elements): array
    {
        $unique = [];
        foreach ($elements as $element) {
            $unique[($element['type'] ?? 'node').'/'.($element['id'] ?? '')] ??= $element;
        }

        // A forest and the reserve covering it often share a name. Offer the
        // larger object first; the import keeps the first one inside the city.
        $green = array_filter($unique, fn (array $element): bool => ($element['_category'] ?? null) === 'green');
        uasort($green, fn (array $a, array $b): int => $this->boundsHectares($b) <=> $this->boundsHectares($a));

        return [...array_values(array_diff_key($unique, $green)), ...array_values($green)];

    }

    /** @param array<string, mixed> $payload */
    protected function dataAgeHours(array $payload): ?float
    {
        $base = $payload['osm3s']['timestamp_osm_base'] ?? null;
        if (! is_string($base) || $base === '') {
            return null;
        }

        try {
            return max(0.0, (CarbonImmutable::now()->getTimestamp() - CarbonImmutable::parse($base)->getTimestamp()) / 3600);
        } catch (Throwable) {
            return null;
        }
    }

    /** overpass-api.de rejects anonymous clients (406); identify the importer and POST so long queries are not URL-bound. */
    protected function overpass(string $mirror, string $query): Response
    {
        return Http::timeout(90)->withUserAgent(self::USER_AGENT)->asForm()->post($mirror, ['data' => $query]);
    }

    /**
     * Minimum bounding-box size in hectares per green-space kind. The box
     * overstates irregular shapes, so the floor is deliberately generous.
     */
    private const GREEN_MIN_HECTARES = ['water' => 2.5, 'recreation_ground' => 1.0, 'default' => 5.0];

    /** Water features and club grounds that share the tags but are not destinations. */
    private const GREEN_NAME_EXCLUSIONS = '/brunnen|becken|sandfang|regenversickerung|absetz|rückhalte|hafen|fontäne|stele|wasserw|kanal|tennis|club|\\be\\.\\s?v\\b|bsg|hundeübung|schutzhof|innenhof/iu';

    /** @param array<string, mixed> $element */
    protected function greenSpaceQualifies(array $element): bool
    {
        $tags = $element['tags'] ?? [];
        $name = trim((string) ($tags['name'] ?? ''));
        $sourceId = ($element['type'] ?? 'node').'/'.($element['id'] ?? '');

        if ($name === '' || preg_match(self::GREEN_NAME_EXCLUSIONS, $name) === 1
            || in_array($sourceId, config('places.green_space_exclusions', []), true)) {
            return false;
        }

        $water = $tags['water'] ?? null;
        if (($tags['natural'] ?? null) === 'water' && $water !== null && ! in_array($water, ['lake', 'pond', 'oxbow', 'reservoir'], true)) {
            return false;
        }

        $kind = match (true) {
            ($tags['natural'] ?? null) === 'water' => 'water',
            ($tags['landuse'] ?? null) === 'recreation_ground' => 'recreation_ground',
            default => 'default',
        };

        return $this->boundsHectares($element) >= self::GREEN_MIN_HECTARES[$kind];
    }

    /** @param array<string, mixed> $element */
    protected function boundsHectares(array $element): float
    {
        $bounds = $element['bounds'] ?? null;
        if (! is_array($bounds) || ! isset($bounds['minlat'], $bounds['maxlat'], $bounds['minlon'], $bounds['maxlon'])) {
            return 0.0;
        }
        $height = ((float) $bounds['maxlat'] - (float) $bounds['minlat']) * 111_320;
        $width = ((float) $bounds['maxlon'] - (float) $bounds['minlon']) * 111_320 * cos(deg2rad((float) $bounds['minlat']));

        return $height * $width / 10_000;
    }

    /** Overpass mirrors replicate minutely; a day of lag means the mirror is stuck. */
    public const MAX_SOURCE_AGE_HOURS = 24;

    public const USER_AGENT = 'Expadu/1.0 (places import; +https://expadu.com)';

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
