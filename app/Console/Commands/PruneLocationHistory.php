<?php

namespace App\Console\Commands;

use App\Services\LocationDataRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class PruneLocationHistory extends Command
{
    protected $signature = 'location:prune-history {--dry-run : Report affected records without changing them}';

    protected $description = 'Enforce seven-day location expiry and redact legacy passive location analytics';

    public function handle(LocationDataRetention $retention): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keys = 0;
        $expired = 0;

        foreach ($retention->keys('location_history:*') as $key) {
            $keys++;
            $expired += $dryRun
                ? (int) Redis::zcount($key, '-inf', now()->timestamp - LocationDataRetention::HISTORY_SECONDS)
                : $retention->pruneHistory($key);
        }

        $payloads = $retention->passivePayloads()->count();

        if (! $dryRun) {
            // Retain event counts and timing, never coordinates,
            // place names or place identifiers from passive tracking.
            $retention->passivePayloads()->update(['payload' => null]);
        }

        $this->info(sprintf('%s%d history keys, %d expired positions, %d passive payloads.', $dryRun ? 'Dry run: ' : 'Processed: ', $keys, $expired, $payloads));

        return self::SUCCESS;
    }
}
