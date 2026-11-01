<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How one Composer plan was ranked (see ComposerController::recordRun).
 * Categories and scores only; never place or event ids.
 */
#[Fillable(['user_id', 'ranker_mode', 'llm_bucket', 'candidate_count', 'slot_count', 'slots'])]
class ComposerRun extends Model
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'llm_bucket' => 'boolean',
            'slots' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
