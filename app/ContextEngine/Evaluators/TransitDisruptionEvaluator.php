<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Services\UserTransitLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Matches a disruption to users via their saved places: a line match
 * (the disrupted line serves a stop near one of the user's places) beats
 * a geo match (a saved place sits inside the disruption bbox).
 *
 * Today (dashboard) is reserved for disruptions that actually touch the user —
 * their line or a saved place — plus city-wide CRITICAL strikes. A merely-major
 * disruption on a line they don't use is still recorded (all onboarded users) but
 * only on the Alerts page, not the home screen: a strike across town isn't an
 * act-now Today need. Push stays reserved for critical disruptions on their line.
 */
class TransitDisruptionEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private UserTransitLinesService $userLines,
        private Scorer $scorer,
    ) {}

    public function handle(TransitDisruptionDetected $event): void
    {
        User::query()
            ->whereNotNull('onboarded_at')
            ->chunkById(100, function ($users) use ($event): void {
                foreach ($users as $user) {
                    $this->evaluateForUser($user, $event);
                }
            });
    }

    private function evaluateForUser(User $user, TransitDisruptionDetected $event): void
    {
        $userLines = $this->userLines->getRelevantLines($user)['lines']->all();
        $lineMatch = (bool) array_intersect($userLines, $event->lines);
        $geoMatch = ! $lineMatch && $this->placeInBbox($user, $event);
        $broadcast = in_array($event->severity, ['critical', 'major'], true);

        if (! $lineMatch && ! $geoMatch && ! $broadcast) {
            return;
        }

        $personalRelevance = match (true) {
            $lineMatch => Scorer::RELEVANCE_ROUTINE_MATCH,
            $geoMatch => Scorer::RELEVANCE_GEO_PROXIMITY,
            default => Scorer::RELEVANCE_SITUATION_MATCH,
        };

        $score = $this->scorer->score(
            severity: $event->severity,
            personalRelevance: $personalRelevance,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'transit_disruption',
            actionKey: "disruption:{$event->disruptionId}:user:{$user->id}",
            score: $score,
            severity: $event->severity,
            validUntil: $event->expiresAt
                ? CarbonImmutable::instance($event->expiresAt)
                : CarbonImmutable::now()->addHours(6),
            deliverChannels: $this->channelsFor($event->severity, $lineMatch, $geoMatch),
            payload: [
                'disruption_id' => $event->disruptionId,
                'lines' => $event->lines,
                'stops_affected' => $event->stopsAffected,
                // Whether a disrupted line is one the user actually rides — the
                // fusion step pairs this with "a commute today" to frame it as an
                // on-your-route need instead of a generic city notice.
                'on_user_line' => $lineMatch,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }

    private function placeInBbox(User $user, TransitDisruptionDetected $event): bool
    {
        if (! $event->bbox) {
            return false;
        }

        return $user->places()
            ->whereBetween('lat', [$event->bbox['minLat'], $event->bbox['maxLat']])
            ->whereBetween('lng', [$event->bbox['minLng'], $event->bbox['maxLng']])
            ->exists();
    }

    /** @return list<string> */
    private function channelsFor(string $severity, bool $lineMatch, bool $geoMatch): array
    {
        $channels = [ScoredAction::CHANNEL_ALERT_PAGE];

        // Dashboard only when the disruption touches the user (their line or a
        // saved place) or is a city-wide critical strike — otherwise Alerts-only.
        if ($lineMatch || $geoMatch || $severity === 'critical') {
            $channels[] = ScoredAction::CHANNEL_DASHBOARD;
        }

        if ($severity === 'critical' && $lineMatch) {
            $channels[] = ScoredAction::CHANNEL_PUSH;
        }

        return $channels;
    }
}
