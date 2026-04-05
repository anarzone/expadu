<?php

namespace App\Console\Commands;

use App\Models\Routine;
use App\Models\User;
use App\Notifications\TransitDelayNotification;
use App\Services\GtfsDepartureService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckTransitDelays extends Command
{
    protected $signature = 'transit:check-delays';

    protected $description = 'Check GTFS-RT for significant delays on user routine lines and notify them';

    private const MIN_DELAY_MINUTES = 10;

    public function handle(GtfsDepartureService $departureService): int
    {
        // Get all active routines with their from_stop
        $routines = Routine::where('is_active', true)->with('user')->get();

        if ($routines->isEmpty()) {
            $this->info('No active routines.');

            return self::SUCCESS;
        }

        // Group routines by from_stop to batch departure lookups
        $byStop = $routines->groupBy('from_stop');
        $notifiedCount = 0;

        foreach ($byStop as $stopName => $stopRoutines) {
            $departures = $departureService->getDepartures($stopName, 10);

            if (empty($departures['departures'])) {
                continue;
            }

            foreach ($departures['departures'] as $dep) {
                $delay = $dep['delay'] ?? 0;
                $line = $dep['line'] ?? '';
                $cancelled = $dep['cancelled'] ?? false;

                if ($delay < self::MIN_DELAY_MINUTES && ! $cancelled) {
                    continue;
                }

                // Find users with routines from this stop
                foreach ($stopRoutines as $routine) {
                    $user = $routine->user;

                    if (! $user || ! $user->wantsNotification('transit')) {
                        continue;
                    }

                    // Dedup: don't re-notify same user about same line within 30 min
                    $dedupKey = "delay_notif:{$user->id}:{$line}";
                    if (Cache::has($dedupKey)) {
                        continue;
                    }

                    Cache::put($dedupKey, true, now()->addMinutes(30));

                    // Throttle check — skip push if throttled
                    if (! NotificationThrottle::canPush($user, 'transit_delay')) {
                        continue;
                    }

                    $user->notify(new TransitDelayNotification($line, $delay, $stopName));
                    NotificationThrottle::recordSent($user);
                    $notifiedCount++;
                }
            }
        }

        $this->info("Sent {$notifiedCount} delay notification(s).");

        if ($notifiedCount > 0) {
            Log::info('Transit delay notifications sent', ['count' => $notifiedCount]);
        }

        return self::SUCCESS;
    }
}
