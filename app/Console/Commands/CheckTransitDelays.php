<?php

namespace App\Console\Commands;

use App\Events\Context\TransitDelayDetected;
use App\Models\User;
use App\Notifications\TransitDelayNotification;
use App\Services\GtfsDepartureService;
use App\Services\UserTransitLinesService;
use App\Support\NotificationThrottle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CheckTransitDelays extends Command
{
    protected $signature = 'transit:check-delays';

    protected $description = 'Check GTFS-RT for significant delays on lines near user places and notify them';

    private const MIN_DELAY_MINUTES = 10;

    public function handle(GtfsDepartureService $departureService, UserTransitLinesService $transitLines): int
    {
        // Build stop → users mapping from all sources (routines + places + GPS)
        $stopUsers = [];
        $userLines = [];

        User::whereNotNull('onboarded_at')->each(function (User $user) use ($transitLines, &$stopUsers, &$userLines) {
            $relevant = $transitLines->getRelevantLines($user);

            if ($relevant['stops']->isEmpty()) {
                return;
            }

            $userLines[$user->id] = $relevant;

            foreach ($relevant['stops'] as $stop) {
                $stopUsers[$stop][] = $user;
            }
        });

        if (empty($stopUsers)) {
            $this->info('No stops to check.');

            return self::SUCCESS;
        }

        $notifiedCount = 0;

        foreach ($stopUsers as $stopName => $users) {
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

                // Dual-emit: context-engine path. Listener fans out per-user.
                $delayDedupKey = "delay_event_emitted:{$line}:{$stopName}";
                if (! Cache::has($delayDedupKey)) {
                    event(new TransitDelayDetected(
                        line: (string) $line,
                        direction: (string) ($dep['direction'] ?? ''),
                        delayMin: $cancelled ? 60 : (int) $delay,
                        stopId: (string) $stopName,
                    ));
                    Cache::put($delayDedupKey, true, now()->addMinutes(30));
                }

                foreach ($users as $user) {
                    // Only notify if this line is relevant to this user
                    $relevant = $userLines[$user->id] ?? null;
                    if (! $relevant || ! $relevant['lines']->contains($line)) {
                        continue;
                    }

                    if (! $user->wantsNotification('transit')) {
                        continue;
                    }

                    // Dedup: don't re-notify same user about same line within 30 min
                    $dedupKey = "delay_notif:{$user->id}:{$line}";
                    if (Cache::has($dedupKey)) {
                        continue;
                    }

                    Cache::put($dedupKey, true, now()->addMinutes(30));

                    if (! NotificationThrottle::canPush($user, 'transit_delay')) {
                        continue;
                    }

                    $context = $relevant['context'][$line] ?? '';
                    $stopLabel = $context ?: $stopName;

                    if (! config('context_engine.push_via_bus')) {
                        $user->notify(new TransitDelayNotification($line, $delay, $stopLabel));
                        NotificationThrottle::recordSent($user);
                        $notifiedCount++;
                    }
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
