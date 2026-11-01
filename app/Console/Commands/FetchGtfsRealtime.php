<?php

namespace App\Console\Commands;

use App\Services\VrsRealtimeService;
use Illuminate\Console\Command;

class FetchGtfsRealtime extends Command
{
    protected $signature = 'transit:fetch-gtfsrt {--force : Fetch even when nobody has read live times in the last ten minutes}';

    protected $description = 'Read VRS\'s GTFS-RT feed once and keep it, so no request waits for it';

    public function handle(VrsRealtimeService $realtime): int
    {
        // 2.6–3.8 s and ~135 MB a read: only while someone reads live times.
        if (! $this->option('force') && ! $realtime->wanted()) {
            $this->line('Nobody has read live times in the last ten minutes; not fetching.');

            return self::SUCCESS;
        }

        $updates = $realtime->refresh();
        if ($updates === null) {
            $this->line('Another fetch is running.');

            return self::SUCCESS;
        }
        if ($updates === []) {
            $this->warn('VRS\'s GTFS-RT feed could not be read.');

            return self::FAILURE;
        }
        $this->info(count($updates).' trip updates.');

        return self::SUCCESS;
    }
}
