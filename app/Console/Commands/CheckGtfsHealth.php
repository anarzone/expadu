<?php

namespace App\Console\Commands;

use App\Services\VrsRealtimeService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Whether the static timetable still fits the live feed. Realtime names trips
 * of VRS's newest timetable only, so a stale import shows up as live trips the
 * timetable doesn't know; and a feed VRS published long ago but we never
 * imported means the refresh isn't running. Either goes to Log::error (Sentry).
 */
class CheckGtfsHealth extends Command
{
    protected $signature = 'gtfs:health';

    protected $description = 'Warn when realtime trips stop matching the timetable, or a published timetable was not imported';

    /** Below this share of live trips found in the timetable, the import is stale. */
    public const MIN_MATCH = 0.9;

    /** Too few live trips (late at night) say nothing about the match. */
    public const MIN_SAMPLE = 20;

    /** How long a newly published timetable may wait for its import. */
    public const MAX_LAG_HOURS = 36;

    public function handle(VrsRealtimeService $realtime): int
    {
        $tripIds = collect($realtime->getTripUpdates())->pluck('trip_id')->filter()->unique()->values();
        if ($tripIds->count() >= self::MIN_SAMPLE) {
            $known = $tripIds->chunk(1000)->sum(fn ($chunk) => DB::table('gtfs_trips')->whereIn('trip_id', $chunk->all())->count());
            $match = $known / $tripIds->count();
            $this->info(sprintf('Realtime trips found in the timetable: %d of %d (%.0f%%).', $known, $tripIds->count(), $match * 100));
            if ($match < self::MIN_MATCH) {
                Log::error('gtfs:health — realtime trips no longer match the timetable; the static import is stale', [
                    'matched' => $known, 'realtime_trips' => $tripIds->count(), 'match' => round($match, 3),
                ]);
            }
        } else {
            $this->info('Too few realtime trips to judge the match ('.$tripIds->count().').');
        }

        $published = RefreshGtfs::publishedVersion();
        $imported = Cache::get(RefreshGtfs::VERSION_KEY);
        if ($published !== null && ! RefreshGtfs::sameVersion($published, $imported) && $published['last_modified'] !== null) {
            $age = CarbonImmutable::parse($published['last_modified'])->diffInHours(now(), true);
            if ($age > self::MAX_LAG_HOURS) {
                Log::error('gtfs:health — VRS published a timetable that has not been imported', [
                    'published' => $published['last_modified'], 'hours_waiting' => (int) $age,
                    'imported' => is_array($imported) ? ($imported['last_modified'] ?? $imported['imported_at'] ?? null) : null,
                ]);
            }
        }

        return self::SUCCESS;
    }
}
