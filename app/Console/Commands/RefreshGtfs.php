<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshGtfs extends Command
{
    protected $signature = 'gtfs:refresh';

    protected $description = 'Download and re-import GTFS static timetable data from VRS';

    public function handle(): int
    {
        $this->info('Refreshing GTFS timetable data from VRS...');

        try {
            // Run the existing import command
            $exitCode = Artisan::call('gtfs:import', [], $this->output);

            if ($exitCode !== 0) {
                $this->error('GTFS import failed');
                Log::error('gtfs:refresh — import failed with exit code '.$exitCode);

                return self::FAILURE;
            }

            // Keep the last good cached boards while the import runs. The
            // short-lived departure caches expire naturally; only the GTFS
            // availability flag needs immediate invalidation. Cache failure
            // after the DB commit is non-fatal and must not report the refresh
            // as failed when the new timetable is already live.
            try {
                Cache::forget('gtfs_has_data');
            } catch (\Throwable $exception) {
                Log::warning('gtfs:refresh — cache invalidation failed after successful import', [
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->info('GTFS refresh complete');
            Log::info('gtfs:refresh — timetable data refreshed successfully');

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error('GTFS refresh error: '.$e->getMessage());
            Log::error('gtfs:refresh — '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
