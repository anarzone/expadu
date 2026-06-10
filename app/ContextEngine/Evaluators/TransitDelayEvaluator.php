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
 * Dashboard/alert-page only for moderate delays; push reserved for
 * major delays (>= 30 min or cancellations) on matched lines.
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
                ? [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE, ScoredAction::CHANNEL_PUSH]
                : [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE],
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
