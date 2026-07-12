<?php

namespace App\Console\Commands\Events;

use App\Exceptions\CologneBoundaryUnavailable;
use App\Services\CologneServiceArea;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditEventCoordinates extends Command
{
    protected $signature = 'events:audit-coordinates
        {--reset : Clear coordinates outside the service area and flag their events for review}';

    protected $description = 'Identify event coordinates outside the Cologne service area';

    /**
     * Execute the console command.
     */
    public function handle(CologneServiceArea $serviceArea): int
    {
        try {
            $invalid = $serviceArea->outsideEvents();
        } catch (CologneBoundaryUnavailable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $count = $invalid->count();

        $this->info("{$count} event(s) outside the Cologne service area.");

        if (! $this->option('reset') || $count === 0) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($invalid): void {
            $invalid->update([
                'location' => null,
                'venue_id' => null,
                'needs_review' => true,
            ]);
        });

        $this->info("Reset {$count} invalid event coordinate(s).");

        return self::SUCCESS;
    }
}
