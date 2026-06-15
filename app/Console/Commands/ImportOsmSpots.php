<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\OpeningHoursParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class ImportOsmSpots extends Command
{
    /**
     * @var string
     */
    protected $signature = 'osm:import {--city=cologne}';

    /**
     * @var string
     */
    protected $description = 'Import cafes, coworking spaces, and libraries from OpenStreetMap via Overpass API';

    public function handle(): int
    {
        $city = $this->option('city');

        if ($city !== 'cologne') {
            $this->error("City \"{$city}\" is not supported yet. Only \"cologne\" is available.");

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
            'playground' => "[out:json][timeout:40];nwr[\"leisure\"=\"playground\"]({$bbox});out center 400;",
            'pitch' => "[out:json][timeout:50];nwr[\"leisure\"=\"pitch\"][\"sport\"~\"soccer|basketball|tennis|table_tennis|boules|skateboard\"]({$bbox});out center 600;",
            'dog_park' => "[out:json][timeout:40];nwr[\"leisure\"=\"dog_park\"]({$bbox});out center;",
            'bbq' => "[out:json][timeout:40];nwr[\"amenity\"=\"bbq\"]({$bbox});out center;",
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

        // Mirrors in preference order — the big city-wide leisure queries
        // get rate-limited on a single endpoint, so fall through on failure.
        $mirrors = [
            'https://overpass.kumi.systems/api/interpreter',
            'https://overpass-api.de/api/interpreter',
            'https://overpass.private.coffee/api/interpreter',
        ];

        $allElements = [];
        foreach ($queries as $category => $query) {
            $this->info("  Fetching {$category}...");
            $fetched = false;

            foreach ($mirrors as $mirror) {
                try {
                    $response = Http::timeout(90)->get($mirror, ['data' => $query]);

                    if ($response->successful()) {
                        $elements = $response->json('elements', []);
                        foreach ($elements as &$el) {
                            $el['_category'] = $category;
                        }
                        $allElements = array_merge($allElements, $elements);
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

        if (empty($allElements)) {
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

        if (count($elements) === 0) {
            $this->warn('No elements returned. The query may have timed out or returned empty.');

            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar(count($elements));
        $bar->setFormat('  %current%/%max% [%bar%] %percent:3s%%');

        $imported = 0;
        $skippedNoName = 0;
        $skippedDuplicate = 0;

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

            $keptTags = $this->keptTags($tags);

            // Check for duplicates: same name within ~50m. Existing rows
            // still get their tags backfilled — earlier imports dropped them.
            $duplicate = Spot::where('name', $name)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->whereRaw('ABS(lat - ?) < 0.0005 AND ABS(lng - ?) < 0.0005', [$lat, $lng])
                ->first();

            if ($duplicate) {
                // Merge newly-whitelisted keys into rows imported before
                // the whitelist grew (e.g. wikidata for the photo pipeline).
                $merged = array_merge($keptTags, is_array($duplicate->tags) ? $duplicate->tags : []);
                if ($merged !== ($duplicate->tags ?? [])) {
                    $duplicate->update(['tags' => $merged]);
                }
                $skippedDuplicate++;

                continue;
            }

            // Build address from OSM tags
            $address = $this->buildAddress($tags);

            Spot::create([
                'name' => $name,
                'category' => $category,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'tags' => $keptTags ?: null,
                'opening_hours' => OpeningHoursParser::parse($tags['opening_hours'] ?? null),
                'wifi_speed' => null,
                'noise_level' => null,
                'rating' => null,
            ]);

            // PostGIS `location` is synced by Spot's saved() hook.
            $imported++;
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('Import complete:');
        $this->info("  Imported: {$imported}");
        $this->info("  Skipped (no name): {$skippedNoName}");
        $this->info("  Skipped (duplicate): {$skippedDuplicate}");

        return self::SUCCESS;
    }

    /**
     * OSM tags worth keeping — they feed the place detail's facts/chips;
     * wikidata/wikipedia link to Commons photos (spots:fetch-photos).
     */
    private const KEPT_TAG_KEYS = [
        'surface', 'lit', 'covered', 'indoor', 'access', 'fee', 'opening_hours',
        'sport', 'hoops', 'wheelchair', 'drinking_water', 'barrier',
        'wikidata', 'wikipedia',
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

        return $hint;
    }

    /**
     * Unnamed playgrounds and pitches are the norm in OSM; synthesise a
     * usable name from the category + street when possible.
     *
     * @param  array<string, string>  $tags
     */
    protected function fallbackName(string $category, array $tags): ?string
    {
        $label = match ($category) {
            'playground' => 'Spielplatz',
            'pitch' => 'Bolzplatz',
            'basketball' => 'Basketballplatz',
            'tennis' => 'Tennisplatz',
            'table_tennis' => 'Tischtennisplatte',
            'boules' => 'Boulebahn',
            'skatepark' => 'Skatepark',
            'dog_park' => 'Hundewiese',
            'bbq' => 'Grillplatz',
            default => null,
        };

        if ($label === null) {
            return null;
        }

        $street = $tags['addr:street'] ?? null;

        return $street ? "{$label} {$street}" : $label;
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
