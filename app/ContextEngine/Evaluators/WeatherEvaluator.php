<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\WeatherChanged;
use App\Models\User;
use App\Models\UserPlace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class WeatherEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    public function handle(WeatherChanged $event): void
    {
        if (empty($event->alerts)) {
            return;
        }

        User::query()
            ->whereNotNull('onboarded_at')
            ->chunkById(100, function ($users) use ($event): void {
                foreach ($users as $user) {
                    $this->evaluateForUser($user, $event);
                }
            });
    }

    private function evaluateForUser(User $user, WeatherChanged $event): void
    {
        $hasNextAnchor = UserPlace::where('user_id', $user->id)
            ->whereNotNull('arrive_by')
            ->get()
            ->contains(fn (UserPlace $p) => $p->isActiveToday());

        $personalRelevance = $hasNextAnchor
            ? Scorer::RELEVANCE_ROUTINE_MATCH
            : Scorer::RELEVANCE_NONE;

        foreach ($event->alerts as $alert) {
            $severity = (string) ($alert['severity'] ?? 'minor');

            $score = $this->scorer->score(
                severity: $severity,
                personalRelevance: $personalRelevance,
                temporalRelevance: $hasNextAnchor
                    ? Scorer::TEMPORAL_INSIDE_WINDOW
                    : Scorer::TEMPORAL_OUTSIDE_VALUE,
            );

            $alertId = (string) ($alert['id'] ?? md5(json_encode($alert)));

            $action = new ScoredAction(
                type: 'weather_alert',
                actionKey: "weather:{$alertId}:user:{$user->id}",
                score: $score,
                severity: $severity,
                validUntil: CarbonImmutable::now()->addHours(6),
                deliverChannels: $hasNextAnchor && in_array($severity, ['major', 'critical'], true)
                    ? [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE, ScoredAction::CHANNEL_PUSH]
                    : [ScoredAction::CHANNEL_DASHBOARD, ScoredAction::CHANNEL_ALERT_PAGE],
                payload: [
                    'condition' => $event->condition,
                    'alert' => $alert,
                ],
                createdAt: CarbonImmutable::now(),
            );

            $this->bus->insert($user, $action);
        }
    }
}
