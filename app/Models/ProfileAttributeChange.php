<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only history of profile attribute changes. The engine only reads
 * current values (users.profile_attributes); this log exists for trust
 * ("why am I seeing this"), debugging, and date-based eligibility math.
 */
#[Fillable(['user_id', 'attribute', 'old_value', 'new_value', 'source'])]
class ProfileAttributeChange extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_value' => 'json',
            'new_value' => 'json',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
