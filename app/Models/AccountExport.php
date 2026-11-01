<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One "Download your data" request (App\Privacy\Export): its ZIP on the
 * private disk, ready for 24 hours, then deleted (account-exports:prune).
 */
#[Fillable(['user_id', 'status', 'path', 'bytes', 'ready_at', 'expires_at'])]
class AccountExport extends Model
{
    use HasUuids;

    /** How long a ready file can be downloaded. */
    public const KEEP_HOURS = 24;

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'ready_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready' && $this->expires_at?->isFuture() === true && $this->path !== null;
    }
}
