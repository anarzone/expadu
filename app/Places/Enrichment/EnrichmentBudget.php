<?php

namespace App\Places\Enrichment;

use App\Ai\LlmCalls;
use App\Models\SpotEnrichmentBatch;
use Illuminate\Support\Facades\DB;

/**
 * The daily spend cap for place enrichment. Spent today means the priced
 * llm_calls rows of both features since midnight, plus the estimate for every
 * batch not yet collected (its real cost lands when it is collected). Each
 * request is estimated from above: its whole body at two bytes a token
 * (a live request measured about 2.1), and max_tokens of output.
 */
class EnrichmentBudget
{
    private ?float $remaining = null;

    public function __construct(private readonly LlmCalls $calls) {}

    public function remaining(): float
    {
        return $this->remaining ??= max(0.0, (float) config('services.place_enrichment.daily_budget_usd') - $this->spentToday());
    }

    public function spentToday(): float
    {
        $called = (float) DB::table('llm_calls')
            ->whereIn('feature', [PlaceEnricher::FEATURE_SYNC, PlaceEnricher::FEATURE_BATCH])
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('cost_usd');
        $open = (float) SpotEnrichmentBatch::query()->where('status', 'in_progress')->sum('estimated_cost_usd');

        return $called + $open;
    }

    /** @param array<string, mixed> $params */
    public function estimate(array $params, bool $batch): float
    {
        $inputTokens = (int) ceil(strlen((string) json_encode($params)) / 2);

        return (float) ($this->calls->cost((string) $params['model'], $inputTokens, (int) $params['max_tokens'], $batch)
            // An unpriced model can't be capped by cost, so it isn't allowed at all.
            ?? INF);
    }

    /** Takes the cost from what is left, or returns false when it doesn't fit. */
    public function spend(float $cost): bool
    {
        if ($cost > $this->remaining()) {
            return false;
        }
        $this->remaining -= $cost;

        return true;
    }
}
