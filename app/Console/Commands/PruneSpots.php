<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete spots in the given categories. Dry-run by default — pass --force to
 * actually delete. Built for the manually-triggered Ops Console workflow so a
 * destructive prune is explicit, auditable, and never the accidental default.
 * Uses the query builder (not Eloquent) so an unknown category string simply
 * matches nothing instead of throwing on the SpotCategory enum cast; the
 * spot_feedback FK cascades at the database level.
 */
class PruneSpots extends Command
{
    protected $signature = 'spots:prune
        {--category=* : Spot category to delete (repeatable), e.g. --category=restaurant}
        {--force : Actually delete; without it the command only reports the count}';

    protected $description = 'Delete spots in the given categories (dry-run unless --force)';

    public function handle(): int
    {
        $categories = array_values(array_filter((array) $this->option('category')));

        if ($categories === []) {
            $this->error('Pass at least one --category (e.g. --category=restaurant --category=bar).');

            return self::FAILURE;
        }

        $list = implode(', ', $categories);
        $count = DB::table('spots')->whereIn('category', $categories)->count();

        if ($count === 0) {
            $this->info("No spots found in: {$list}. Nothing to do.");

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn("[dry-run] would delete {$count} spot(s) in: {$list}. Re-run with --force to delete.");

            return self::SUCCESS;
        }

        $deleted = DB::table('spots')->whereIn('category', $categories)->delete();
        $this->info("Deleted {$deleted} spot(s) in: {$list}.");

        return self::SUCCESS;
    }
}
