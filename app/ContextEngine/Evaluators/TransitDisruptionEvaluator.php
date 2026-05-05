<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\AlternativeRoutePlanner;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Models\UserRouteCache;
use App\Services\UserTransitLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class TransitDisruptionEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private AlternativeRoutePlanner $planner,
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
        // Source 1: precomputed Home↔anchor routes (catches transfer-only lines)
        $routes = UserRouteCache::where('user_id', $user->id)->get();
        $matchedRoute = $routes->first(
            fn (UserRouteCache $r) => array_intersect($r->lines ?? [], $event->lines)
        );

        // Source 2: legacy union (routines + nearby-place stops + GPS)
        $legacyLines = $this->userLines->getRelevantLines($user)['lines']->all();
        $legacyMatch = (bool) array_intersect($legacyLines, $event->lines);

        if (! $matchedRoute && ! $legacyMatch && ! $this->geoMatches($user, $event)) {
            return;
        }

        $personalRelevance = match (true) {
            $matchedRoute !== null => Scorer::RELEVANCE_ROUTE_MATCH,
            $legacyMatch => Scorer::RELEVANCE_ROUTINE_MATCH,
            default => Scorer::RELEVANCE_GEO_PROXIMITY,
        };

        $temporalRelevance = $this->temporalRelevance($matchedRoute);

        $score = $this->scorer->score(
            severity: $event->severity,
            personalRelevance: $personalRelevance,
            temporalRelevance: $temporalRelevance,
        );

        $disruptionAction = new ScoredAction(
            type: 'transit_disruption',
            actionKey: "disruption:{$event->disruptionId}:user:{$user->id}",
            score: $score,
            severity: $event->severity,
            validUntil: $event->expiresAt
                ? CarbonImmutable::instance($event->expiresAt)
                : CarbonImmutable::now()->addHours(6),
            deliverChannels: $this->channelsFor($event->severity, $personalRelevance),
            payload: [
                'disruption_id' => $event->disruptionId,
                'lines' => $event->lines,
                'stops_affected' => $event->stopsAffected,
                'matched_route_id' => $matchedRoute?->id,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $disruptionAction);

        if ($matchedRoute) {
            $alt = $this->planner->propose($user, $matchedRoute, $event->lines);

            $altAction = new ScoredAction(
                type: $alt ? 'alternative_route' : 'disruption_no_alt',
                actionKey: "disruption_alt:{$event->disruptionId}:user:{$user->id}:route:{$matchedRoute->id}",
                score: $score - 0.1, // sit just below the disruption card
                severity: $event->severity,
                validUntil: $event->expiresAt
                    ? CarbonImmutable::instance($event->expiresAt)
                    : CarbonImmutable::now()->addHours(6),
                deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
                payload: [
                    'disruption_id' => $event->disruptionId,
                    'matched_route_id' => $matchedRoute->id,
                    'alternative' => $alt,
                ],
                createdAt: CarbonImmutable::now(),
            );

            $this->bus->insert($user, $altAction);
        }
    }

    private function temporalRelevance(?UserRouteCache $route): float
    {
        if (! $route) {
            return Scorer::TEMPORAL_OUTSIDE_VALUE;
        }

        if ($route->isInTypicalWindow()) {
            return Scorer::TEMPORAL_INSIDE_WINDOW;
        }
        if ($route->isNearTypicalWindow(Scorer::TEMPORAL_NEAR_WINDOW_MIN)) {
            return Scorer::TEMPORAL_NEAR_WINDOW_VALUE;
        }
        if ($route->isNearTypicalWindow(120)) {
            return Scorer::TEMPORAL_WITHIN_2H_VALUE;
        }

        return Scorer::TEMPORAL_OUTSIDE_VALUE;
    }

    private function geoMatches(User $user, TransitDisruptionDetected $event): bool
    {
        if (! $event->bbox) {
            return false;
        }

        $stationary = $this->userLines->getStationaryLocation($user);
        if (! $stationary) {
            return false;
        }

        $lat = (float) ($stationary['lat'] ?? 0);
        $lng = (float) ($stationary['lng'] ?? 0);

        return $lat >= $event->bbox['minLat']
            && $lat <= $event->bbox['maxLat']
            && $lng >= $event->bbox['minLng']
            && $lng <= $event->bbox['maxLng'];
    }

    /** @return list<string> */
    private function channelsFor(string $severity, float $personalRelevance): array
    {
        $channels = [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE];

        $shouldPush = $severity === 'critical'
            || ($severity === 'major' && $personalRelevance >= Scorer::RELEVANCE_ROUTINE_MATCH);

        if ($shouldPush) {
            $channels[] = ScoredAction::CHANNEL_PUSH;
        }

        return $channels;
    }
}
