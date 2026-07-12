<?php

namespace App\Console\Commands\Veedels;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports Cologne's 86 official Stadtteil polygons. Keeping the polygon and
 * its derived centroid together prevents places outside the city from being
 * assigned to whichever Cologne centroid happens to be nearest.
 */
class ImportVeedels extends Command
{
    protected $signature = 'veedels:import';

    protected $description = 'Import official Cologne Stadtteil polygons and centroids into the veedels table';

    private const DATASET_URL = 'https://services.arcgis.com/ObdAEOfl1Z5LP2D0/ArcGIS/rest/services/K%C3%B6ln/FeatureServer/7/query';

    public function handle(): int
    {
        $this->info('Downloading official Cologne Stadtteil polygons…');

        try {
            $response = Http::connectTimeout(10)->timeout(60)
                ->withHeaders(['User-Agent' => 'expadu.com'])
                ->get(self::DATASET_URL, [
                    'where' => '1=1',
                    'outFields' => 'NAME,STADTBEZIR',
                    'returnGeometry' => 'true',
                    'outSR' => 4326,
                    'f' => 'geojson',
                ])
                ->throw();
        } catch (\Throwable $e) {
            $this->error("Official boundary request failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $bezirkByVeedel = [];
        foreach (config('veedels', []) as $bezirk => $stadtteile) {
            foreach ($stadtteile as $name) {
                $bezirkByVeedel[$name] = $bezirk;
            }
        }

        $staged = [];

        foreach ($response->json('features', []) as $feature) {
            $name = $this->canonicalName($feature['properties']['NAME'] ?? null);
            $geometry = $feature['geometry'] ?? null;

            if (! $name || ! is_array($geometry) || ! in_array($geometry['type'] ?? null, ['Polygon', 'MultiPolygon'], true)) {
                continue;
            }

            if (! isset($bezirkByVeedel[$name])) {
                continue;
            }

            $geoJson = json_encode($geometry, JSON_THROW_ON_ERROR);
            $isValid = DB::selectOne(
                'SELECT ST_IsValid(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326))) AS valid',
                [$geoJson],
            );
            if (! ($isValid->valid ?? false)) {
                continue;
            }
            $staged[$name] = ['bezirk' => $bezirkByVeedel[$name], 'geojson' => $geoJson];
        }

        $expectedNames = array_keys($bezirkByVeedel);
        sort($expectedNames);
        $receivedNames = array_keys($staged);
        sort($receivedNames);
        if (count($staged) !== 86 || $receivedNames !== $expectedNames) {
            $this->error('Boundary dataset is incomplete or unexpected; existing polygons were not changed.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($staged): void {
            foreach ($staged as $name => $row) {
                DB::statement(<<<'SQL'
                INSERT INTO veedels (name, bezirk, centroid_lat, centroid_lng, boundary, created_at, updated_at)
                VALUES (?, ?,
                    ST_Y(ST_PointOnSurface(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))),
                    ST_X(ST_PointOnSurface(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)))),
                    ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)), ?, ?)
                ON CONFLICT (name) DO UPDATE SET
                    bezirk = EXCLUDED.bezirk,
                    centroid_lat = EXCLUDED.centroid_lat,
                    centroid_lng = EXCLUDED.centroid_lng,
                    boundary = EXCLUDED.boundary,
                    updated_at = EXCLUDED.updated_at
                SQL, [$name, $row['bezirk'], $row['geojson'], $row['geojson'], $row['geojson'], now(), now()]);
            }
        });

        $this->info('Imported 86 Stadtteile.');

        return self::SUCCESS;
    }

    private function canonicalName(?string $name): ?string
    {
        return match ($name) {
            'Altstadt/Nord' => 'Altstadt-Nord',
            'Altstadt/Süd' => 'Altstadt-Süd',
            'Neustadt/Nord' => 'Neustadt-Nord',
            'Neustadt/Süd' => 'Neustadt-Süd',
            default => $name,
        };
    }
}
