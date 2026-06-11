<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where an event happens — first-class so the composer can route to it
 * and so events link back to seeded places (place_id when a venue sits
 * within ~50m of one).
 */
#[Fillable(['name', 'lat', 'lng', 'veedel', 'address_text', 'place_id'])]
class Venue extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /** @return BelongsTo<Spot, $this> */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Spot::class, 'place_id');
    }

    /** @return HasMany<Event, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
