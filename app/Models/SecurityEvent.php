<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the security log. Written by App\Security\SecurityLog only;
 * model:prune drops rows after RetentionDays.
 *
 * @property string $type
 * @property string $channel
 * @property ?string $reason
 * @property ?string $email_domain
 * @property ?string $ip
 */
class SecurityEvent extends Model
{
    use MassPrunable;

    /** Said in the privacy policy: change both together. */
    public const RetentionDays = 90;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::RetentionDays));
    }
}
