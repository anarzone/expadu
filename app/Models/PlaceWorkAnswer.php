<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One person's "Good for laptop work?" answer for one place. Personal data. */
#[Fillable(['user_id', 'spot_id', 'laptop', 'wifi', 'sockets', 'quiet', 'answered_at'])]
class PlaceWorkAnswer extends Model
{
    protected function casts(): array
    {
        return ['answered_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Spot, $this> */
    public function spot(): BelongsTo
    {
        return $this->belongsTo(Spot::class);
    }
}
