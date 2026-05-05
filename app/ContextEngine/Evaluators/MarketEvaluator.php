<?php

namespace App\ContextEngine\Evaluators;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\ContextEngine\Scorer;
use App\Events\Context\MarketClosureDetected;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class MarketEvaluator implements ShouldQueue
{
    use InteractsWithQueue;

    public string $queue = 'commute';

    public function __construct(
        private ActionBus $bus,
        private Scorer $scorer,
    ) {}

    public function handle(MarketClosureDetected $event): void
    {
        User::query()
            ->whereNotNull('onboarded_at')
            ->chunkById(100, function ($users) use ($event): void {
                foreach ($users as $user) {
                    $score = $this->scorer->score(
                        severity: 'minor',
                        personalRelevance: Scorer::RELEVANCE_GEO_PROXIMITY,
                        temporalRelevance: Scorer::TEMPORAL_NEAR_WINDOW_VALUE,
                    );

                    $this->bus->insert($user, new ScoredAction(
                        type: 'market_closure',
                        actionKey: "market:{$event->marketId}:{$event->day}:user:{$user->id}",
                        score: $score,
                        severity: 'minor',
                        validUntil: CarbonImmutable::now()->endOfDay(),
                        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
                        payload: [
                            'market_id' => $event->marketId,
                            'day' => $event->day,
                            'reason' => $event->reason,
                        ],
                        createdAt: CarbonImmutable::now(),
                    ));
                }
            });
    }
}
