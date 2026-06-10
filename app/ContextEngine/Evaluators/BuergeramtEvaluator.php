<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\BuergeramtSlotsAvailable;
use App\Models\SlotMonitor;
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
        /** @var array<int, true> $monitoringUserIds */
        $monitoringUserIds = SlotMonitor::query()
            ->where('is_active', true)
            ->where('office_id', $event->officeId)
            ->pluck('user_id')
            ->flip()
            ->all();

        User::query()
            ->whereNotNull('onboarded_at')
            ->where(function ($query) use ($monitoringUserIds): void {
                $query->whereIn('situation', self::RELEVANT_SITUATIONS);
                if ($monitoringUserIds !== []) {
                    $query->orWhereIn('id', array_keys($monitoringUserIds));
                }
            })
            ->chunkById(100, function ($users) use ($event, $monitoringUserIds): void {
                foreach ($users as $user) {
                    $this->evaluateForUser($user, $event, isset($monitoringUserIds[$user->id]));
                }
            });
    }

    private function evaluateForUser(User $user, BuergeramtSlotsAvailable $event, bool $isMonitoring): void
    {
        if (method_exists($user, 'wantsNotification') && ! $user->wantsNotification('burgeramt')) {
            return;
        }

        $score = $this->scorer->score(
            severity: 'critical',
            personalRelevance: $isMonitoring
                ? Scorer::RELEVANCE_ROUTE_MATCH
                : Scorer::RELEVANCE_SITUATION_MATCH,
            temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
        );

        // Push only goes to users who explicitly monitor this office.
        // Situation-matched users see the slot on the dashboard/alerts only —
        // pushing every non-EU user for every office opening is spam.
        $channels = [
            ScoredAction::CHANNEL_DASHBOARD,
            ScoredAction::CHANNEL_ALERT_PAGE,
        ];
        if ($isMonitoring) {
            $channels[] = ScoredAction::CHANNEL_PUSH;
        }

        $action = new ScoredAction(
            type: 'buergeramt_slot',
            actionKey: "buergeramt:{$event->officeId}:user:{$user->id}",
            score: $score,
            severity: 'critical',
            validUntil: CarbonImmutable::now()->addHours(2),
            deliverChannels: $channels,
            payload: [
                'office_id' => $event->officeId,
                'dates' => $event->dates,
            ],
            createdAt: CarbonImmutable::now(),
        );

        $this->bus->insert($user, $action);
    }
}
