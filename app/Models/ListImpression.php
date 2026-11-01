<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One list a person was shown (see App\Personalisation\ListImpressions).
 * Written by ListImpressions only; this model exists so model:prune drops
 * rows past the ledger's retention window.
 */
class ListImpression extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['baseline' => 'array', 'shown' => 'array', 'first_seen_at' => 'immutable_datetime', 'last_seen_at' => 'immutable_datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('last_seen_at', '<', now()->subDays((int) config('personalisation.retention_days', 90))->toIso8601String());
    }
}
