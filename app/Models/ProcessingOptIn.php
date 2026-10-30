<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A standing yes to one kind of processing (ProcessingOptIns): which, under which notice (and its words) and provider, and whether it's withdrawn. */
#[Fillable(['user_id', 'purpose', 'notice_version', 'provider_version', 'notice', 'granted_at', 'withdrawn_at'])]
class ProcessingOptIn extends Model
{
    protected function casts(): array
    {
        return [
            'notice' => 'array',
            'granted_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
