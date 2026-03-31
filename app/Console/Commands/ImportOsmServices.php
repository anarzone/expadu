<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ImportOsmServices extends Command
{
    protected $signature = 'osm:import-services';

    protected $description = 'Import doctors, pharmacies, dentists, banks, and lawyers from OpenStreetMap via Overpass API';

    public function handle(): int
    {
        $this->info('Querying Overpass API for Cologne services...');

        $bbox = '50.90,6.85,50.99,7.05'; // Wider Cologne area

        $queries = [
            'doctor' => "[out:json][timeout:30];(node[\"amenity\"=\"doctors\"]({$bbox});node[\"healthcare\"=\"doctor\"]({$bbox}););out body;",
            'pharmacy' => "[out:json][timeout:30];node[\"amenity\"=\"pharmacy\"]({$bbox});out body;",
            'dental' => "[out:json][timeout:30];node[\"amenity\"=\"dentist\"]({$bbox});out body;",
            'bank' => "[out:json][timeout:30];node[\"amenity\"=\"bank\"]({$bbox});out body;",
            'legal' => "[out:json][timeout:30];node[\"office\"=\"lawyer\"]({$bbox});out body;",
            'tax' => "[out:json][timeout:30];node[\"office\"=\"tax_advisor\"]({$bbox});out body;",
            'insure' => "[out:json][timeout:30];node[\"office\"=\"insurance\"]({$bbox});out body;",
        ];

        $categoryEmojis = [
            'doctor' => '🩺',
            'pharmacy' => '💊',
            'dental' => '🦷',
            'bank' => '🏦',
            'legal' => '⚖️',
            'tax' => '📋',
            'insure' => '🛡️',
        ];

        $totalCreated = 0;

        foreach ($queries as $category => $query) {
            $this->info("  Fetching {$category}...");

            try {
                $response = Http::timeout(90)
                    ->get('https://overpass.kumi.systems/api/interpreter', ['data' => $query]);

                if (! $response->successful()) {
                    $this->warn("    {$category} query failed: status {$response->status()}");
                    sleep(2);

                    continue;
                }

                $elements = $response->json('elements', []);
                $created = 0;

                foreach ($elements as $el) {
                    $tags = $el['tags'] ?? [];
                    $name = $tags['name'] ?? null;
                    $lat = $el['lat'] ?? null;
                    $lng = $el['lon'] ?? null;

                    if (! $name || ! $lat || ! $lng) {
                        continue;
                    }

                    $osmId = 'node/'.$el['id'];

                    if (Service::where('osm_id', $osmId)->exists()) {
                        continue;
                    }

                    // Build address from OSM tags
                    $address = collect([
                        $tags['addr:street'] ?? null,
                        $tags['addr:housenumber'] ?? null,
                    ])->filter()->implode(' ');

                    if (isset($tags['addr:postcode']) || isset($tags['addr:city'])) {
                        $address .= ($address ? ', ' : '').collect([
                            $tags['addr:postcode'] ?? null,
                            $tags['addr:city'] ?? 'Köln',
                        ])->filter()->implode(' ');
                    }

                    // Extract languages from tags
                    $languages = [];
                    if (isset($tags['language:en']) && $tags['language:en'] === 'yes') {
                        $languages[] = 'English';
                    }
                    if (isset($tags['language:de']) || ! isset($tags['language:en'])) {
                        $languages[] = 'German';
                    }

                    $service = Service::create([
                        'name' => $name,
                        'category' => $category,
                        'emoji' => $categoryEmojis[$category] ?? '📍',
                        'description' => $tags['description'] ?? null,
                        'address' => $address ?: null,
                        'lat' => $lat,
                        'lng' => $lng,
                        'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                        'website' => $tags['website'] ?? $tags['contact:website'] ?? null,
                        'languages' => $languages ?: null,
                        'opening_hours' => isset($tags['opening_hours']) ? ['raw' => $tags['opening_hours']] : null,
                        'source' => 'osm',
                        'osm_id' => $osmId,
                        'quality_score' => $this->computeQuality($tags, $address),
                    ]);

                    // Set PostGIS location
                    DB::statement(
                        'UPDATE services SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?',
                        [$lng, $lat, $service->id]
                    );

                    $created++;
                }

                $totalCreated += $created;
                $this->info("    Imported {$created} {$category} services (from ".count($elements).' OSM nodes)');
                sleep(3); // Rate limit courtesy
            } catch (\Exception $e) {
                $this->warn("    {$category} error: {$e->getMessage()}");
                sleep(2);
            }
        }

        $this->info("Total: {$totalCreated} services imported");

        return self::SUCCESS;
    }

    protected function computeQuality(array $tags, string $address): float
    {
        $score = 0.3; // Has name (required to get here)

        if ($address) {
            $score += 0.2;
        }
        if (isset($tags['phone']) || isset($tags['contact:phone'])) {
            $score += 0.15;
        }
        if (isset($tags['website']) || isset($tags['contact:website'])) {
            $score += 0.15;
        }
        if (isset($tags['opening_hours'])) {
            $score += 0.1;
        }
        if (isset($tags['healthcare:speciality']) || isset($tags['description'])) {
            $score += 0.1;
        }

        return min(1.0, $score);
    }
}
