<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * School, kindergarten and education grounds from OSM, and on each facility the
 * smallest grounds it stands on (coordinator's call, 24 Oct 2026): a pitch on a
 * daycare's or school's grounds isn't offered as public unless the city's register
 * or an access tag says it is (PlaceFacts::publicUse). Like parks:import-areas.
 */
#[Signature('places:import-school-grounds {--stamp-only : re-mark facilities from the grounds already imported, without asking Overpass}')]
#[Description('Import OSM school, kindergarten and education grounds and mark the facilities on them')]
class ImportSchoolGrounds extends Command
{
    /** Outdoor play and sport facilities a school's grounds can hold; shops and cafés on a campus aren't its grounds'. */
    public const FACILITIES = ['pitch', 'playground', 'basketball', 'tennis', 'table_tennis', 'boules', 'skatepark', 'picnic', 'dog_park'];

    private const MIRRORS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
    ];

    public function handle(): int
    {
        if ($this->option('stamp-only')) {
            $this->info('Facilities on school grounds: '.$this->assign().'.');

            return self::SUCCESS;
        }
        $bbox = '50.83,6.77,51.09,7.16'; // all of Cologne
        $query = "[out:json][timeout:120];(wr[\"amenity\"~\"^(school|kindergarten)$\"]({$bbox});wr[\"landuse\"=\"education\"]({$bbox}););out geom;";

        $elements = null;
        foreach (self::MIRRORS as $mirror) {
            try {
                $response = Http::timeout(180)->withUserAgent(ImportOsmSpots::USER_AGENT)->asForm()->post($mirror, ['data' => $query]);
                if ($response->successful()) {
                    $elements = $response->json('elements', []);
                    break;
                }
                $this->warn("  {$mirror}: status {$response->status()}");
            } catch (\Exception $e) {
                $this->warn("  {$mirror}: {$e->getMessage()}");
            }
        }
        if ($elements === null) {
            $this->error('All Overpass mirrors failed.');

            return self::FAILURE;
        }

        $imported = 0;
        $skipped = 0;
        foreach ($elements as $element) {
            $type = $element['type'] ?? null;
            $wkt = $this->toWkt($element);
            if (! in_array($type, ['way', 'relation'], true) || $wkt === null) {
                $skipped++;

                continue;
            }
            $tags = $element['tags'] ?? [];
            $kind = match (true) {
                ($tags['amenity'] ?? null) === 'kindergarten' => 'kindergarten',
                ($tags['amenity'] ?? null) === 'school' => 'school',
                default => 'education',
            };
            DB::statement(
                'INSERT INTO school_areas (osm_id, source_id, kind, name, boundary, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ST_MakeValid('.$wkt['expr'].'), now(), now())
                 ON CONFLICT (osm_id) DO UPDATE
                 SET source_id = EXCLUDED.source_id, kind = EXCLUDED.kind, name = EXCLUDED.name, boundary = EXCLUDED.boundary, updated_at = now()',
                // Ways and relations are separate id spaces (…1 way, …2 relation), as in parks:import-areas.
                [$element['id'] * 10 + ($type === 'relation' ? 2 : 1), "{$type}/{$element['id']}", $kind, $tags['name'] ?? null, $wkt['wkt']],
            );
            $imported++;
        }
        $this->info("School grounds: {$imported} imported/updated, {$skipped} skipped.");
        $this->info('Facilities on school grounds: '.$this->assign().'.');

        return self::SUCCESS;
    }

    /** Stamps each facility with the smallest grounds covering it, and clears those no longer on any. */
    private function assign(): int
    {
        $facilities = collect(self::FACILITIES)
            ->filter(fn (string $category): bool => SpotCategory::tryFrom($category) !== null)
            ->map(fn (string $category): string => "'{$category}'")->implode(', ');

        DB::statement("UPDATE spots SET grounds_kind = NULL, grounds_name = NULL, grounds_source_id = NULL
            WHERE grounds_kind IS NOT NULL AND (category NOT IN ({$facilities}) OR location IS NULL
              OR NOT EXISTS (SELECT 1 FROM school_areas a WHERE ST_Covers(a.boundary, spots.location::geometry)))");
        DB::statement("UPDATE spots SET grounds_kind = g.kind, grounds_name = g.name, grounds_source_id = g.source_id
            FROM (
                SELECT s.id, a.kind, a.name, a.source_id FROM spots s
                CROSS JOIN LATERAL (
                    SELECT kind, name, source_id FROM school_areas
                    WHERE ST_Covers(boundary, s.location::geometry) ORDER BY ST_Area(boundary) LIMIT 1
                ) a
                WHERE s.category IN ({$facilities}) AND s.location IS NOT NULL
            ) g
            WHERE spots.id = g.id AND (spots.grounds_source_id IS DISTINCT FROM g.source_id OR spots.grounds_name IS DISTINCT FROM g.name)");

        return DB::table('spots')->whereNotNull('grounds_kind')->count();
    }

    /**
     * @param  array<string, mixed>  $element
     * @return array{expr: string, wkt: string}|null
     */
    private function toWkt(array $element): ?array
    {
        if (($element['type'] ?? null) === 'way' && ! empty($element['geometry'])) {
            $ring = array_map(fn (array $p): string => sprintf('%.7F %.7F', $p['lon'], $p['lat']), $element['geometry']);
            if (count($ring) < 4) {
                return null;
            }
            if ($ring[0] !== end($ring)) {
                $ring[] = $ring[0];
            }

            return ['expr' => 'ST_GeomFromText(?, 4326)', 'wkt' => 'POLYGON(('.implode(', ', $ring).'))'];
        }
        if (($element['type'] ?? null) === 'relation' && ! empty($element['members'])) {
            $points = [];
            foreach ($element['members'] as $member) {
                if (($member['role'] ?? 'outer') !== 'outer') {
                    continue;
                }
                foreach ($member['geometry'] ?? [] as $p) {
                    $points[] = sprintf('(%.7F %.7F)', $p['lon'], $p['lat']);
                }
            }
            if (count($points) < 3) {
                return null;
            }

            return ['expr' => 'ST_ConvexHull(ST_GeomFromText(?, 4326))', 'wkt' => 'MULTIPOINT('.implode(', ', $points).')'];
        }

        return null;
    }
}
