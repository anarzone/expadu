<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A stop the person starred in Travel's Nearby, by its KVB station name. */
#[Fillable(['user_id', 'name'])]
class FavouriteStop extends Model
{
    /** Favourites lead Plan a trip's start; more than this isn't a shortlist. */
    public const LIMIT = 20;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
