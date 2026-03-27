<?php

namespace App\Console\Commands;

use App\Models\Spot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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

        // Fetch each category separately to avoid timeouts
        $bbox = '50.92,6.92,50.96,6.97'; // Cologne inner city
        $queries = [
            'cafe' => "[out:json][timeout:25];node[\"amenity\"=\"cafe\"]({$bbox});out body;",
            'coworking' => "[out:json][timeout:25];(node[\"amenity\"=\"coworking_space\"]({$bbox});node[\"office\"=\"coworking\"]({$bbox}););out body;",
            'library' => "[out:json][timeout:25];node[\"amenity\"=\"library\"]({$bbox});out body;",
        ];

        $allElements = [];
        foreach ($queries as $category => $query) {
            $this->info("  Fetching {$category}...");
            try {
                $response = Http::timeout(90)
                    ->get('https://overpass.kumi.systems/api/interpreter', ['data' => $query]);

                if ($response->successful()) {
                    $elements = $response->json('elements', []);
                    foreach ($elements as &$el) {
                        $el['_category'] = $category;
                    }
                    $allElements = array_merge($allElements, $elements);
                    $this->info('    Found '.count($elements)." {$category} spots");
                } else {
                    $this->warn("    {$category} query failed: status {$response->status()}");
                }
                sleep(2); // Rate limit courtesy
            } catch (\Exception $e) {
                $this->warn("    {$category} query error: {$e->getMessage()}");
            }
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
            $name = $tags['name'] ?? null;

            // Skip elements without a name
            if (! $name) {
                $skippedNoName++;

                continue;
            }

            $lat = (float) $element['lat'];
            $lng = (float) $element['lon'];

            // Determine category from tags
            $category = $this->resolveCategory($tags);

            // Check for duplicates: same name within ~50m
            $duplicate = Spot::where('name', $name)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->whereRaw('ABS(lat - ?) < 0.0005 AND ABS(lng - ?) < 0.0005', [$lat, $lng])
                ->exists();

            if ($duplicate) {
                $skippedDuplicate++;

                continue;
            }

            // Build address from OSM tags
            $address = $this->buildAddress($tags);

            $spot = Spot::create([
                'name' => $name,
                'category' => $category,
                'address' => $address,
                'lat' => $lat,
                'lng' => $lng,
                'wifi_speed' => null,
                'noise_level' => null,
                'rating' => null,
            ]);

            // Set PostGIS location
            DB::statement(
                'UPDATE spots SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                [$lng, $lat, $spot->id]
            );

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
     * Resolve the spot category from OSM tags.
     *
     * @param  array<string, string>  $tags
     */
    protected function resolveCategory(array $tags): string
    {
        $amenity = $tags['amenity'] ?? '';
        $office = $tags['office'] ?? '';

        if ($amenity === 'coworking_space' || $office === 'coworking') {
            return 'coworking';
        }

        if ($amenity === 'library') {
            return 'library';
        }

        return 'cafe';
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
