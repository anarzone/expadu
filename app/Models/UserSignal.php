<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the personalisation ledger (see App\Personalisation). Written by
 * SignalRecorder only; this model exists so model:prune drops rows past the
 * retention window.
 */
class UserSignal extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['context' => 'array', 'created_at' => 'immutable_datetime'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays((int) config('personalisation.retention_days', 90))->toIso8601String());
    }
}
