<?php

namespace App\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One row per LLM call: feature, model, prompt version, tokens, latency and
 * outcome — enough for cost and fallback rate per feature. No user link and
 * no prompt or response text. Cost is computed only from prices configured in
 * services.llm_pricing; an unpriced model records null rather than a guess.
 * A Message Batches call bills at half the standard price.
 */
class LlmCalls
{
    public const BATCH_PRICE_FACTOR = 0.5;

    public function record(
        string $feature,
        ?string $model,
        int $latencyMs,
        string $outcome,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?string $promptVersion = null,
        bool $batch = false,
    ): void {
        try {
            DB::table('llm_calls')->insert([
                'feature' => $feature,
                'model' => $model,
                'prompt_version' => $promptVersion,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'latency_ms' => max(0, $latencyMs),
                'outcome' => mb_substr($outcome, 0, 120),
                'cost_usd' => $this->cost($model, $inputTokens, $outputTokens, $batch),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Telemetry must never break the feature it measures.
            Log::warning('llm call could not be recorded', ['feature' => $feature, 'error' => $exception->getMessage()]);
        }
    }

    public function cost(?string $model, ?int $inputTokens, ?int $outputTokens, bool $batch = false): ?float
    {
        $price = $model !== null ? config("services.llm_pricing.{$model}") : null;
        if (! is_array($price) || $inputTokens === null || $outputTokens === null) {
            return null;
        }

        return round(
            ($inputTokens / 1_000_000 * (float) ($price['input_per_mtok'] ?? 0)
            + $outputTokens / 1_000_000 * (float) ($price['output_per_mtok'] ?? 0))
            * ($batch ? self::BATCH_PRICE_FACTOR : 1.0),
            6,
        );
    }
}
