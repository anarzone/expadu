<?php

namespace App\Console\Commands\Veedels;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Deletes spots that fall outside the City of Cologne's administrative
 * boundary. The OSM import plus nearest-centroid Veedel assignment pulled in
 * spots from neighbouring municipalities (Leverkusen, Bergisch Gladbach,
 * Pulheim) and tagged them with the closest Cologne Veedel — e.g. the
 * Leverkusen Wildpark animal enclosures surfacing under "Merkenich".
 *
 * Dry-run by default (reports the count); pass --force to delete. Related
 * spot_feedback / reviews / check-ins cascade at the DB level.
 */
class PruneOutsideCologne extends Command
{
    protected $signature = 'spots:prune-outside-cologne {--force : Actually delete; without it only reports the count}';

    protected $description = 'Delete spots outside the Cologne city boundary (mis-imported neighbouring municipalities)';

    /** Spots with no point inside the Cologne admin polygon. */
    private const OUTSIDE = 'lat IS NOT NULL AND lng IS NOT NULL AND NOT ST_Contains(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), ST_SetSRID(ST_MakePoint(lng::float8, lat::float8), 4326))';

    public function handle(): int
    {
        $boundary = $this->cologneBoundary();

        if ($boundary === null) {
            $this->error('Could not load the Cologne boundary from Nominatim.');

            return self::FAILURE;
        }

        $count = DB::table('spots')->whereRaw(self::OUTSIDE, [$boundary])->count();
        $this->info("Spots outside the Cologne boundary: {$count}");

        if ($count === 0) {
            return self::SUCCESS;
        }

        $sample = DB::table('spots')
            ->whereRaw(self::OUTSIDE, [$boundary])
            ->orderBy('veedel')
            ->limit(15)
            ->get(['name', 'veedel', 'category']);

        foreach ($sample as $spot) {
            $this->line("  {$spot->name} [{$spot->veedel}/{$spot->category}]");
        }

        if (! $this->option('force')) {
            $this->warn("[dry-run] would delete {$count} spot(s). Re-run with --force to delete.");

            return self::SUCCESS;
        }

        $deleted = DB::table('spots')->whereRaw(self::OUTSIDE, [$boundary])->delete();
        $this->info("Deleted {$deleted} out-of-Cologne spot(s).");

        return self::SUCCESS;
    }

    /**
     * The Cologne city polygon as a GeoJSON string, cached 30 days (it never
     * really changes). Returns null if Nominatim is unreachable or hands back
     * something that isn't a polygon.
     */
    private function cologneBoundary(): ?string
    {
        return Cache::remember('cologne_boundary_geojson', now()->addDays(30), function () {
            $geo = Http::withHeaders(['User-Agent' => 'expadu.com'])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q' => 'Köln, Germany',
                    'format' => 'jsonv2',
                    'polygon_geojson' => 1,
                    'limit' => 1,
                ])
                ->json('0.geojson');

            return $geo && in_array($geo['type'] ?? '', ['Polygon', 'MultiPolygon'], true)
                ? json_encode($geo)
                : null;
        });
    }
}
