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
            // Clear GTFS cache so fresh data is used
            Cache::forget('gtfs_has_data');
            Cache::flush(); // Clear all departure caches

            // Run the existing import command
            $exitCode = Artisan::call('gtfs:import', [], $this->output);

            if ($exitCode !== 0) {
                $this->error('GTFS import failed');
                Log::error('gtfs:refresh — import failed with exit code '.$exitCode);

                return self::FAILURE;
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
