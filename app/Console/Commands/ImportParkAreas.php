<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Imports mapped Cologne destination areas from OSM and stamps each contained
 * leisure facility with a stable parent spot. Parks and named sports centres
 * become one destination; their pitches/courts remain activities, never cards.
 */
class ImportParkAreas extends Command
{
    protected $signature = 'parks:import-areas';

    protected $description = 'Import OSM destination areas and assign contained spots by geometry';

    private const MIRRORS = [
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    public function handle(): int
    {
        $bbox = '50.83,6.77,51.09,7.16'; // all of Cologne
        $query = "[out:json][timeout:60];(nwr[\"leisure\"=\"park\"][\"name\"]({$bbox});nwr[\"leisure\"=\"sports_centre\"][\"name\"][\"sport\"~\"soccer|football|basketball|tennis|table_tennis|multi\"]({$bbox}););out geom;";

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
            $type = $element['type'] ?? null;
            if (! $name || ! $osmId || ! in_array($type, ['way', 'relation'], true)) {
                $skipped++;

                continue;
            }

            // OSM ways and relations are separate id spaces — encode the
            // type into the stored id (…1 = way, …2 = relation) so an id
            // clash can't overwrite the other's polygon.
            $osmId = $osmId * 10 + ($type === 'relation' ? 2 : 1);
            $sourceId = "{$type}/{$element['id']}";
            $kind = ($element['tags']['leisure'] ?? null) === 'sports_centre'
                ? 'sports_centre'
                : 'park';

            $wkt = $this->toWkt($element);
            if ($wkt === null) {
                $skipped++;

                continue;
            }

            $parentSpotId = DB::table('spots')
                ->where('source', 'osm')
                ->where('source_id', $sourceId)
                ->value('id');

            DB::statement(
                'INSERT INTO park_areas (name, osm_id, kind, source_id, parent_spot_id, boundary, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ST_MakeValid('.$wkt['expr'].'), now(), now())
                 ON CONFLICT (osm_id) DO UPDATE
                 SET name = EXCLUDED.name, kind = EXCLUDED.kind, source_id = EXCLUDED.source_id,
                     parent_spot_id = EXCLUDED.parent_spot_id, boundary = EXCLUDED.boundary, updated_at = now()',
                [$name, $osmId, $kind, $sourceId, $parentSpotId, $wkt['wkt']],
            );
            $imported++;
        }

        $this->info("Destination areas: {$imported} imported/updated, {$skipped} skipped.");

        $assigned = $this->assignDestinations();
        $this->info("Facilities inside a destination: {$assigned}.");

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
     * Stamp every eligible facility with its smallest containing destination.
     * The direct parent ID is stable if a park name changes; park_name remains
     * populated as a legacy display fallback until all existing data has been
     * refreshed through this importer.
     */
    private function assignDestinations(): int
    {
        $fines = collect(SpotCategory::placesFines())
            ->reject(fn (string $category) => in_array($category, ['park', 'sports_centre'], true))
            ->map(fn (string $category) => "'{$category}'")
            ->implode(', ');

        DB::statement(
            "UPDATE spots SET parent_spot_id = NULL, park_name = NULL
             WHERE category IN ({$fines}) AND location IS NOT NULL",
        );

        DB::statement(
            "UPDATE spots AS child
             SET parent_spot_id = destination.parent_spot_id,
                 park_name = destination.name
             FROM (
                 SELECT candidate.id, area.parent_spot_id, area.name
                 FROM spots AS candidate
                 CROSS JOIN LATERAL (
                     SELECT parent_spot_id, name
                     FROM park_areas
                     WHERE parent_spot_id IS NOT NULL
                       AND ST_Covers(boundary, candidate.location::geometry)
                     ORDER BY ST_Area(boundary) ASC
                     LIMIT 1
                 ) AS area
                 WHERE candidate.category IN ({$fines})
                   AND candidate.location IS NOT NULL
             ) AS destination
             WHERE child.id = destination.id",
        );

        return DB::table('spots')->whereNotNull('parent_spot_id')->count();
    }
}
