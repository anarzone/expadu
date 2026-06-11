<?php

namespace App\Console\Commands\Veedels;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports Cologne's 86 Stadtteile with centroids from OSM admin
 * boundaries (admin_level=10). Bezirk grouping comes from
 * config/veedels.php. Polygon boundaries are a P1 upgrade — centroid
 * assignment is good enough for Veedel-granularity filtering.
 */
class ImportVeedels extends Command
{
    protected $signature = 'veedels:import';

    protected $description = 'Import Cologne Stadtteile centroids from OSM into the veedels table';

    public function handle(): int
    {
        $this->info('Querying Overpass for Cologne Stadtteile…');

        $query = '[out:json][timeout:50];'
            .'area["name"="Köln"]["admin_level"="6"]->.k;'
            .'relation["admin_level"="10"]["boundary"="administrative"](area.k);'
            .'out tags center;';

        try {
            $response = Http::timeout(60)
                ->withHeaders(['User-Agent' => 'expadu.com'])
                ->get('https://overpass-api.de/api/interpreter', ['data' => $query])
                ->throw();
        } catch (\Throwable $e) {
            $this->error("Overpass request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $bezirkByVeedel = [];
        foreach (config('veedels', []) as $bezirk => $stadtteile) {
            foreach ($stadtteile as $name) {
                $bezirkByVeedel[$name] = $bezirk;
            }
        }

        $imported = 0;
        $unknown = [];

        foreach ($response->json('elements', []) as $element) {
            $name = $element['tags']['name'] ?? null;
            $center = $element['center'] ?? null;

            // OSM writes these two with a slash; the city (and our config)
            // use a hyphen.
            $name = match ($name) {
                'Neustadt/Nord' => 'Neustadt-Nord',
                'Neustadt/Süd' => 'Neustadt-Süd',
                default => $name,
            };
            if (! $name || ! $center) {
                continue;
            }

            if (! isset($bezirkByVeedel[$name])) {
                $unknown[] = $name;

                continue;
            }

            DB::table('veedels')->updateOrInsert(
                ['name' => $name],
                [
                    'bezirk' => $bezirkByVeedel[$name],
                    'centroid_lat' => $center['lat'],
                    'centroid_lng' => $center['lon'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
            $imported++;
        }

        $this->info("Imported {$imported} Stadtteile.");
        if ($unknown !== []) {
            $this->warn('Not in config/veedels.php (check spelling): '.implode(', ', $unknown));
        }

        return self::SUCCESS;
    }
}
