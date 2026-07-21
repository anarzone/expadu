<?php

namespace App\Console\Commands\Events;

use App\Models\Event;
use App\Services\VenueResolver;
use Illuminate\Console\Command;

/**
 * Backfill venue links for events that missed them. Venue resolution
 * normally happens inside ProcessEventJob, but historically that job
 * died on classification errors before linking, and importers only
 * re-dispatch an event when its content changes — so old events stayed
 * venue-less forever and could never inherit venue media. Pure DB work
 * (no external calls), idempotent, safe to run on a schedule.
 */
class LinkEventVenues extends Command
{
    protected $signature = 'events:link-venues {--limit=1000 : Max events to link per run}';

    protected $description = 'Link venue-less events to venues by location name (heals pre-classification-failure orphans)';

    public function handle(VenueResolver $venues): int
    {
        $linked = 0;
        $failed = 0;

        Event::query()
            ->whereNull('venue_id')
            ->whereNotNull('location_name')
            ->where('location_name', '!=', '')
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (Event $event) use ($venues, &$linked, &$failed) {
                try {
                    $venue = $venues->resolve(
                        $event->location_name,
                        $event->address,
                        $event->lat,
                        $event->lng,
                    );
                    $event->update(['venue_id' => $venue->id]);
                    $linked++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("  event {$event->id}: {$e->getMessage()}");
                }
            });

        $this->info("Linked {$linked} event(s) to venues".($failed > 0 ? ", {$failed} failed" : '').'.');

        return self::SUCCESS;
    }
}
