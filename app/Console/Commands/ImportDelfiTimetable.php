<?php

namespace App\Console\Commands;

use App\Transit\DelfiCancellations;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ImportDelfiTimetable extends Command
{
    protected $signature = 'transit:import-delfi
        {--force : Import even when gtfs.de has not published a new timetable}
        {--file= : A gtfs.de static zip already downloaded, instead of fetching it}';

    protected $description = 'Import the city\'s slice of gtfs.de\'s static timetable, so its realtime cancellations can be placed';

    public function handle(DelfiCancellations $delfi): int
    {
        $file = $this->option('file');
        $published = $file ? null : $delfi->publishedVersion();
        if (! $file && ! $this->option('force') && $published !== null && $published === Cache::get(DelfiCancellations::VERSION_KEY)) {
            $this->info('gtfs.de has not published a new timetable since the last import; nothing to do.');

            return self::SUCCESS;
        }

        // About 280 MB: to disk, never into memory.
        $download = storage_path('app/delfi-nv.zip');
        if (! $file) {
            $this->info('Downloading gtfs.de\'s timetable…');
            $response = Http::timeout(900)->sink($download)->get((string) config('services.gtfsde.static_url'));
            if (! $response->successful()) {
                @unlink($download);
                $this->error('gtfs.de\'s timetable could not be downloaded (HTTP '.$response->status().').');

                return self::FAILURE;
            }
        }

        try {
            $kept = $delfi->import($file ?: $download);
        } finally {
            if (! $file) {
                @unlink($download);
            }
        }

        if ($published !== null) {
            Cache::forever(DelfiCancellations::VERSION_KEY, $published);
        }
        $this->info("Kept {$kept} calls in the area.");

        return self::SUCCESS;
    }
}
