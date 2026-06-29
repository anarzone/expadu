<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Replace this environment's spots with the committed snapshot, so every
 * environment holds the identical, sanitized dataset — instead of each running
 * its own osm:import (which pulls a different Overpass slice every time).
 *
 * All spot foreign keys cascade/set-null on delete, so the TRUNCATE clears
 * dependent reviews/feedback (test data pre-launch); venues.place_id is nulled.
 */
class LoadSpots extends Command
{
    protected $signature = 'spots:load {--path=database/data/spots.json.gz : Snapshot file (relative to the app root)} {--force : Skip the confirmation prompt}';

    protected $description = 'Replace the spots table with the committed snapshot';

    public function handle(): int
    {
        $path = base_path($this->option('path'));
        if (! is_file($path)) {
            $this->error('Snapshot not found: '.$this->option('path'));

            return self::FAILURE;
        }

        $rows = json_decode(gzdecode((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);

        $this->line('Snapshot holds '.count($rows).' spots; this environment currently has '.DB::table('spots')->count().'.');

        if (! $this->option('force') && ! $this->confirm('Replace all spots (cascading reviews/feedback)?')) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($rows) {
            DB::statement('TRUNCATE spots RESTART IDENTITY CASCADE');

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('spots')->insert($chunk);
            }

            // Rebuild the geometry from the loaded coordinates, then move the id
            // sequence past the highest explicit id we just inserted.
            DB::statement('UPDATE spots SET location = ST_SetSRID(ST_MakePoint(lng, lat), 4326) WHERE lat IS NOT NULL AND lng IS NOT NULL');
            DB::statement("SELECT setval(pg_get_serial_sequence('spots', 'id'), GREATEST((SELECT max(id) FROM spots), 1))");
        });

        $this->info('Loaded '.count($rows).' spots.');

        return self::SUCCESS;
    }
}
