<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drop noisy sub-feature attractions — the enclosure labels inside the Kölner
 * Zoo / a Wildpark and the figure labels inside a Märchenwald, which OSM tags
 * as tourism=attraction but which are never standalone destinations.
 */
class SanitizeSpots extends Command
{
    protected $signature = 'spots:sanitize {--dry-run : List what would be removed without deleting}';

    protected $description = 'Remove sub-feature attractions that cluster densely (zoo/wildpark/Märchenwald labels)';

    /** An attraction with at least this many attraction-neighbours nearby is a sub-feature, not a destination. */
    private const CLUSTER_MIN_NEIGHBOURS = 5;

    private const CLUSTER_RADIUS_M = 300;

    public function handle(): int
    {
        // Real landmarks (the Dom, towers, mills) sit nearly alone (0–2 nearby
        // attractions); a dense knot of attractions is one parent site's
        // enclosure/figure signs (the zoo has ~12, a Wildpark ~36, a
        // Märchenwald ~20 within 300m).
        $rows = DB::select(<<<'SQL'
            WITH a AS (SELECT id, name, lat, lng FROM spots WHERE category = 'attraction' AND lat IS NOT NULL)
            SELECT a.id, a.name
            FROM a
            WHERE (
                SELECT count(*) FROM a b
                WHERE b.id <> a.id
                  AND (6371000 * acos(LEAST(1, cos(radians(a.lat)) * cos(radians(b.lat)) * cos(radians(b.lng) - radians(a.lng)) + sin(radians(a.lat)) * sin(radians(b.lat))))) <= ?
            ) >= ?
            ORDER BY a.name
            SQL, [self::CLUSTER_RADIUS_M, self::CLUSTER_MIN_NEIGHBOURS]);

        $ids = array_map(fn ($r) => $r->id, $rows);

        if ($ids === []) {
            $this->info('Nothing to sanitize — no dense attraction clusters found.');

            return self::SUCCESS;
        }

        $this->line(count($ids).' clustered sub-feature attractions:');
        $this->line(collect($rows)->pluck('name')->implode(', '));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — nothing deleted.');

            return self::SUCCESS;
        }

        DB::table('spots')->whereIn('id', $ids)->delete();
        $this->info('Removed '.count($ids).' spots.');

        return self::SUCCESS;
    }
}
