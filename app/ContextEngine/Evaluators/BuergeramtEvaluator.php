<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\BuergeramtSlotsAvailable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class BuergeramtEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    private const RELEVANT_SITUATIONS = ['non_eu_employee', 'student', 'family_reunification', 'eu_employee'];

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    public function handle(BuergeramtSlotsAvailable $event): void
    {
        User::query()
            ->whereNotNull('onboarded_at')
            ->whereIn('situation', self::RELEVANT_SITUATIONS)
            ->chunkById(100, function ($users) use ($event): void {
                foreach ($users as $user) {
                    $this->evaluateForUser($user, $event);
                }
            });
    }

    private function evaluateForUser(User $user, BuergeramtSlotsAvailable $event): void
    {
        if (method_exists($user, 'wantsNotification') && ! $user->wantsNotification('burgeramt')) {
            return;
        }

        $score = $this->scorer->score(
            severity: 'critical',
            personalRelevance: Scorer::RELEVANCE_SITUATION_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        $action = new ScoredAction(
            type: 'buergeramt_slot',
            actionKey: "buergeramt:{$event->officeId}:user:{$user->id}",
            score: $score,
            severity: 'critical',
            validUntil: CarbonImmutable::now()->addHours(2),
            deliverChannels: [
                ScoredAction::CHANNEL_DASHBOARD,
                ScoredAction::CHANNEL_ALERT_PAGE,
                ScoredAction::CHANNEL_PUSH,
            ],
            payload: [
                'office_id' => $event->officeId,
                'dates' => $event->dates,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }
}
