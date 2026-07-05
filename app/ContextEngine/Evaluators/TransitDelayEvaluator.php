<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\TransitDelayDetected;
use App\Models\User;
use App\Services\UserTransitLinesService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Matches a delayed line to users whose saved places are served by it.
 * Alert-page only: a line delay is a genuine Today need only when the user is
 * actually about to board it (a live departure), which this evaluator can't yet
 * know — "a saved place is on this line" is not "boarding now", so it isn't
 * act-now enough for a dashboard tile. It stays on the record, and a major delay
 * (>= 30 min or cancellation) still pushes. Fusing with a live leave-by (Phase 2)
 * is what earns it back onto Today.
 */
class TransitDelayEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private UserTransitLinesService $userLines,
        private Scorer $scorer,
    ) {}

    public function handle(TransitDelayDetected $event): void
    {
        $severity = $event->delayMin >= 30 ? 'major'
            : ($event->delayMin >= 15 ? 'moderate'
                : ($event->delayMin >= 10 ? 'minor' : 'info'));

        User::query()
            ->whereNotNull('onboarded_at')
            ->chunkById(100, function ($users) use ($event, $severity): void {
                foreach ($users as $user) {
                    $this->evaluateForUser($user, $event, $severity);
                }
            });
    }

    private function evaluateForUser(User $user, TransitDelayDetected $event, string $severity): void
    {
        $userLines = $this->userLines->getRelevantLines($user)['lines']->all();

        if (! in_array($event->line, $userLines, true)) {
            return;
        }

        $score = $this->scorer->score(
            severity: $severity,
            personalRelevance: Scorer::RELEVANCE_ROUTINE_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'transit_delay',
            actionKey: "delay:{$event->line}:{$event->direction}:user:{$user->id}",
            score: $score,
            severity: $severity,
            validUntil: CarbonImmutable::now()->addHours(2),
            deliverChannels: $event->delayMin >= 30
                ? [ScoredAction::CHANNEL_ALERT_PAGE, ScoredAction::CHANNEL_PUSH]
                : [ScoredAction::CHANNEL_ALERT_PAGE],
            payload: [
                'line' => $event->line,
                'direction' => $event->direction,
                'delay_min' => $event->delayMin,
                'stop_id' => $event->stopId,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }
}
