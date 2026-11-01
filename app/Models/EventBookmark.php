<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'event_id', 'occurrence_start', 'title'])]
class EventBookmark extends Model
{
    // Preserve the instant when PostgreSQL's session timezone differs from the city.
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected function casts(): array
    {
        return ['occurrence_start' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
