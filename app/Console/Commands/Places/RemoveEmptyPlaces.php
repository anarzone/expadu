<?php

namespace App\Console\Commands\Places;

use App\Models\UserPlace;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Once, after EXP-193: onboarding used to make an empty Home and Work for
 * everyone (no address, no point), and they stood in for real ones. This
 * deletes those placeholders: no address, no point, never edited since they
 * were made. Prints counts by kind, never anything about the people.
 */
class RemoveEmptyPlaces extends Command
{
    protected $signature = 'places:remove-empty {--dry-run : Count what would go without deleting}';

    protected $description = 'Delete the empty Home/Work placeholders onboarding used to create';

    public function handle(): int
    {
        $empty = UserPlace::query()
            ->where(fn (Builder $query) => $query->whereNull('address')->orWhere('address', ''))
            ->whereNull('lat')
            ->whereNull('lng')
            ->whereColumn('updated_at', 'created_at');

        $counts = (clone $empty)
            ->selectRaw("coalesce(category, 'none') as kind, count(*) as n")
            ->groupBy('kind')
            ->orderBy('kind')
            ->pluck('n', 'kind');

        foreach ($counts as $kind => $n) {
            $this->line("{$kind}: {$n}");
        }

        if ($this->option('dry-run')) {
            $this->info('Would delete '.$counts->sum().' empty place(s).');

            return self::SUCCESS;
        }

        $deleted = $empty->delete();
        $this->info("Deleted {$deleted} empty place(s).");

        return self::SUCCESS;
    }
}
