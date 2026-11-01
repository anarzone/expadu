<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Message Batch of place enrichment requests submitted to Anthropic:
 * in_progress until places:enrich-collect has applied its results, then
 * collected (or failed when the results can no longer be fetched). Places in
 * an open batch are not submitted again.
 */
#[Fillable(['provider_batch_id', 'status', 'request_count', 'spot_ids', 'estimated_cost_usd', 'model', 'prompt_version', 'result_counts', 'submitted_at', 'collected_at'])]
class SpotEnrichmentBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'spot_ids' => 'array',
            'result_counts' => 'array',
            'estimated_cost_usd' => 'float',
            'submitted_at' => 'datetime',
            'collected_at' => 'datetime',
        ];
    }

    /** @return list<int> places waiting in a batch that hasn't been collected */
    public static function openSpotIds(): array
    {
        return static::query()->where('status', 'in_progress')->pluck('spot_ids')
            ->flatten()->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }
}
