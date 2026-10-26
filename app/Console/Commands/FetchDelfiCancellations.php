<?php

namespace App\Console\Commands;

use App\Transit\DelfiCancellations;
use Illuminate\Console\Command;

class FetchDelfiCancellations extends Command
{
    protected $signature = 'transit:fetch-delfi {--force : Fetch even when nobody has looked at Travel in the last ten minutes}';

    protected $description = 'Read gtfs.de\'s realtime feed once and keep the city\'s cancelled calls for Travel\'s boards';

    public function handle(DelfiCancellations $delfi): int
    {
        // 30 MB a read: only while someone is looking at Travel.
        if (! $this->option('force') && ! $delfi->wanted()) {
            $this->line('Nobody has looked at Travel in the last ten minutes; not fetching.');

            return self::SUCCESS;
        }

        $count = $delfi->fetch();
        if ($count === null) {
            $this->warn('gtfs.de\'s realtime feed could not be read.');

            return self::FAILURE;
        }
        $this->info("{$count} cancelled calls in the area.");

        return self::SUCCESS;
    }
}
