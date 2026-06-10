<?php

namespace App\Console\Commands;

use App\Events\Context\TransitDelayDetected;
use App\Models\User;
use App\Services\GtfsDepartureService;
use App\Services\UserTransitLinesService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckTransitDelays extends Command
{
    protected $signature = 'transit:check-delays';

    protected $description = 'Check GTFS-RT for significant delays on lines near user places and emit context events';

    private const MIN_DELAY_MINUTES = 10;

    public function handle(GtfsDepartureService $departureService, UserTransitLinesService $transitLines): int
    {
        // Build the set of stops worth polling from users' relevant lines.
        $stops = [];

        User::whereNotNull('onboarded_at')->each(function (User $user) use ($transitLines, &$stops) {
            $relevant = $transitLines->getRelevantLines($user);

            foreach ($relevant['stops'] as $stop) {
                $stops[$stop] = true;
            }
        });

        if (empty($stops)) {
            $this->info('No stops to check.');

            return self::SUCCESS;
        }

        $emitted = 0;

        foreach (array_keys($stops) as $stopName) {
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

                // Per-user matching, scoring, and push delivery happen in
                // TransitDelayEvaluator via the ActionBus.
                $delayDedupKey = "delay_event_emitted:{$line}:{$stopName}";
                if (! Cache::has($delayDedupKey)) {
                    event(new TransitDelayDetected(
                        line: (string) $line,
                        direction: (string) ($dep['direction'] ?? ''),
                        delayMin: $cancelled ? 60 : (int) $delay,
                        stopId: (string) $stopName,
                    ));
                    Cache::put($delayDedupKey, true, now()->addMinutes(30));
                    $emitted++;
                }
            }
        }

        $this->info("Emitted {$emitted} delay event(s).");

        return self::SUCCESS;
    }
}
