<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Composer answer as it was made: the message, the tools called with their input and what
 * they returned, the answer as written and which sentences held. Written by Ask only; this model
 * exists so model:prune drops rows after RETENTION_DAYS. Paperwork results are never kept; a
 * deleted conversation takes its rows, and a deleted Paperwork record takes them all
 * (WorkspaceStore).
 */
class ComposerTrace extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'written' => 'array',
            'kept' => 'array',
            'dropped' => 'array',
            'timings' => 'array',
            'usage' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
