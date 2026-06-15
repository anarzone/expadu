<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Services\OpeningHoursParser;
use Illuminate\Console\Command;

/**
 * The OSM import keeps the raw `opening_hours` string inside the `tags` JSON
 * but never populated the structured `opening_hours` column, so the composer
 * scheduled venues blind to when they're actually open. This extracts the
 * common forms (parser skips the exotic seasonal/free-text ones).
 */
class BackfillSpotOpeningHours extends Command
{
    protected $signature = 'spots:backfill-opening-hours {--dry-run : Report what would change without writing}';

    protected $description = 'Extract the OSM opening_hours tag into the structured opening_hours column';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $parsed = 0;
        $skipped = 0;
        $noTag = 0;

        Spot::query()
            ->whereNotNull('tags')
            ->chunkById(500, function ($spots) use (&$parsed, &$skipped, &$noTag, $dryRun) {
                foreach ($spots as $spot) {
                    $raw = is_array($spot->tags) ? ($spot->tags['opening_hours'] ?? null) : null;
                    if (! is_string($raw)) {
                        $noTag++;

                        continue;
                    }

                    $hours = OpeningHoursParser::parse($raw);
                    if ($hours === null) {
                        $skipped++;

                        continue;
                    }

                    if (! $dryRun) {
                        $spot->opening_hours = $hours;
                        $spot->save();
                    }
                    $parsed++;
                }
            });

        $this->info(($dryRun ? '[dry-run] ' : '')."Opening hours — parsed: {$parsed}, unparseable: {$skipped}, no tag: {$noTag}.");

        return self::SUCCESS;
    }
}
