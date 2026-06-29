<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Export every spot (all columns, ids preserved) to a gzipped JSON snapshot —
 * the canonical, sanitized dataset that spots:load replays into staging and
 * production so all three environments hold byte-identical data.
 */
class SnapshotSpots extends Command
{
    protected $signature = 'spots:snapshot {--path=database/data/spots.json.gz : Output file (relative to the app root)}';

    protected $description = 'Write the local spots table to a gzipped JSON snapshot';

    public function handle(): int
    {
        $path = base_path($this->option('path'));
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        // The PostGIS `location` geometry is derived from lat/lng — drop it so
        // the snapshot is plain JSON; spots:load regenerates it on the far side.
        $rows = DB::table('spots')->orderBy('id')->get()
            ->map(fn ($r) => collect((array) $r)->except(['location'])->all())
            ->all();

        file_put_contents($path, gzencode(json_encode($rows, JSON_THROW_ON_ERROR), 9));

        $this->info('Wrote '.count($rows).' spots → '.$this->option('path').' ('.round(filesize($path) / 1024).' KB).');

        return self::SUCCESS;
    }
}
