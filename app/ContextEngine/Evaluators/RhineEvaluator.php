<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\RhineLevelChanged;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RhineEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    public function handle(RhineLevelChanged $event): void
    {
        if (! $event->thresholdCrossed) {
            return;
        }

        $severity = match ($event->thresholdCrossed) {
            'flood', 'critical' => 'major',
            'warning' => 'moderate',
            default => 'info',
        };

        User::query()
            ->whereNotNull('onboarded_at')
            ->chunkById(100, function ($users) use ($event, $severity): void {
                foreach ($users as $user) {
                    $score = $this->scorer->score(
                        severity: $severity,
                        personalRelevance: Scorer::RELEVANCE_GEO_PROXIMITY,
                        temporalRelevance: Scorer::TEMPORAL_INSIDE_WINDOW,
                    );

                    $this->bus->insert($user, new ScoredAction(
                        type: 'rhine_level',
                        actionKey: "rhine:{$event->thresholdCrossed}:user:{$user->id}",
                        score: $score,
                        severity: $severity,
                        validUntil: CarbonImmutable::now()->addHours(12),
                        // Alert-page only: a river gauge reading is not an act-now
                        // Today need for the general population (no action, no plan
                        // intersection). It stays on the record for the flood-exposed
                        // to browse. Promote back to Today only when fused with a
                        // riverside place/route at a genuinely disruptive level.
                        deliverChannels: [ScoredAction::CHANNEL_ALERT_PAGE],
                        payload: [
                            'level' => $event->level,
                            'threshold' => $event->thresholdCrossed,
                        ],
                        createdAt: CarbonImmutable::now(),
                    ));
                }
            });
    }
}
