<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports Cologne park polygons from OSM and stamps every leisure
 * facility with the park that contains it (ST_Contains, smallest park
 * wins for nested areas). Simple park ways become exact polygons;
 * multipolygon relations fall back to the convex hull of their member
 * geometry — slightly generous, but right for "is this in the park?".
 */
class ImportParkAreas extends Command
{
    protected $signature = 'parks:import-areas';

    protected $description = 'Import OSM park polygons and assign spots.park_name by containment';

    private const MIRRORS = [
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    public function handle(): int
    {
        $bbox = '50.83,6.77,51.09,7.16'; // all of Cologne
        $query = "[out:json][timeout:60];nwr[\"leisure\"=\"park\"][\"name\"]({$bbox});out geom;";

        $elements = $this->fetch($query);
        if ($elements === null) {
            $this->error('All Overpass mirrors failed.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;

        foreach ($elements as $element) {
            $name = $element['tags']['name'] ?? null;
            $osmId = $element['id'] ?? null;
            if (! $name || ! $osmId) {
                $skipped++;

                continue;
            }

            // OSM ways and relations are separate id spaces — encode the
            // type into the stored id (…1 = way, …2 = relation) so an id
            // clash can't overwrite the other's polygon.
            $osmId = $osmId * 10 + (($element['type'] ?? null) === 'relation' ? 2 : 1);

            $wkt = $this->toWkt($element);
            if ($wkt === null) {
                $skipped++;

                continue;
            }

            DB::statement(
                'INSERT INTO park_areas (name, osm_id, boundary, created_at, updated_at)
                 VALUES (?, ?, ST_MakeValid('.$wkt['expr'].'), now(), now())
                 ON CONFLICT (osm_id) DO UPDATE
                 SET name = EXCLUDED.name, boundary = EXCLUDED.boundary, updated_at = now()',
                [$name, $osmId, $wkt['wkt']],
            );
            $imported++;
        }

        $this->info("Park areas: {$imported} imported/updated, {$skipped} skipped.");

        $assigned = $this->assignParks();
        $this->info("Facilities inside a park: {$assigned}.");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function fetch(string $query): ?array
    {
        foreach (self::MIRRORS as $mirror) {
            try {
                $response = Http::timeout(120)->get($mirror, ['data' => $query]);

                if ($response->successful()) {
                    return $response->json('elements', []);
                }

                $this->warn("  {$mirror}: status {$response->status()}");
            } catch (\Exception $e) {
                $this->warn("  {$mirror}: {$e->getMessage()}");
            }
        }

        return null;
    }

    /**
     * Build a WKT geometry for the element. Returns the SQL expression
     * (parameterised) and the WKT string, or null when no usable geometry.
     *
     * @param  array<string, mixed>  $element
     * @return array{expr: string, wkt: string}|null
     */
    private function toWkt(array $element): ?array
    {
        if (($element['type'] ?? null) === 'way' && ! empty($element['geometry'])) {
            $ring = array_map(
                fn (array $p) => sprintf('%.7F %.7F', $p['lon'], $p['lat']),
                $element['geometry'],
            );

            if (count($ring) < 4) {
                return null;
            }

            // Close the ring if OSM didn't
            if ($ring[0] !== end($ring)) {
                $ring[] = $ring[0];
            }

            return [
                'expr' => 'ST_GeomFromText(?, 4326)',
                'wkt' => 'POLYGON(('.implode(', ', $ring).'))',
            ];
        }

        if (($element['type'] ?? null) === 'relation' && ! empty($element['members'])) {
            $points = [];
            foreach ($element['members'] as $member) {
                foreach ($member['geometry'] ?? [] as $p) {
                    $points[] = sprintf('(%.7F %.7F)', $p['lon'], $p['lat']);
                }
            }

            if (count($points) < 3) {
                return null;
            }

            return [
                'expr' => 'ST_ConvexHull(ST_GeomFromText(?, 4326))',
                'wkt' => 'MULTIPOINT('.implode(', ', $points).')',
            ];
        }

        return null;
    }

    /**
     * Stamp every non-park leisure facility with its containing park
     * (smallest containing area wins); clears the name where no park
     * contains the spot, so re-runs stay correct.
     */
    private function assignParks(): int
    {
        $fines = collect(SpotCategory::placesFines())
            ->reject(fn (string $category) => $category === 'park')
            ->map(fn (string $category) => "'{$category}'")
            ->implode(', ');

        DB::statement(
            "UPDATE spots SET park_name = (
                SELECT pa.name FROM park_areas pa
                WHERE ST_Contains(pa.boundary, spots.location::geometry)
                ORDER BY ST_Area(pa.boundary) ASC
                LIMIT 1
            )
            WHERE spots.category IN ({$fines}) AND spots.location IS NOT NULL",
        );

        return DB::table('spots')->whereNotNull('park_name')->count();
    }
}
