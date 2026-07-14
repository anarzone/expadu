<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Where an event happens — first-class so the composer can route to it
 * and so events link back to seeded places (place_id when a venue sits
 * within ~50m of one).
 */
#[Fillable(['name', 'lat', 'lng', 'veedel', 'address_text', 'place_id'])]
class Venue extends Model
{
    protected static function booted(): void
    {
        static::deleting(function (Venue $venue): void {
            $venue->mediaAttachments()->delete();
        });
    }

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

    /** @return MorphMany<MediaAttachment, $this> */
    public function mediaAttachments(): MorphMany
    {
        return $this->morphMany(MediaAttachment::class, 'mediable');
    }
}
