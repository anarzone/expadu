<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\UserContextChanged;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class LeaveByEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    public function handle(UserContextChanged $event): void
    {
        if ($event->contextType !== 'leave_by_due') {
            return;
        }

        $user = User::find($event->userId);
        if (! $user) {
            return;
        }

        $score = $this->scorer->score(
            severity: 'major',
            personalRelevance: Scorer::RELEVANCE_ROUTE_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'leave_by',
            actionKey: "leave_by:user:{$user->id}:place:{$event->placeId}:".$event->at->format('Y-m-d-H'),
            score: $score,
            severity: 'major',
            validUntil: CarbonImmutable::instance($event->at)->addMinutes(30),
            deliverChannels: [
                ScoredAction::CHANNEL_DASHBOARD,
                ScoredAction::CHANNEL_PUSH,
                ScoredAction::CHANNEL_ALERT_PAGE,
            ],
            payload: [
                'place_id' => $event->placeId,
                'at' => $event->at->toIso8601String(),
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }
}
