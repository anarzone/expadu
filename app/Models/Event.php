<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'emoji', 'category', 'description', 'starts_at', 'ends_at', 'location_name', 'address', 'max_attendees', 'is_free', 'price', 'price_text', 'organiser_id', 'tags', 'is_expat_relevant', 'source', 'source_url', 'quality_score'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    protected $table = 'events';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_free' => 'boolean',
            'price' => 'decimal:2',
            'tags' => 'array',
            'is_expat_relevant' => 'boolean',
            'quality_score' => 'float',
        ];
    }

    /**
     * Extract latitude from PostGIS location column.
     */
    public function getLatAttribute(): ?float
    {
        if (! $this->location) {
            return null;
        }

        $result = \DB::selectOne('SELECT ST_Y(location::geometry) as lat FROM events WHERE id = ?', [$this->id]);

        return $result?->lat ? (float) $result->lat : null;
    }

    /**
     * Extract longitude from PostGIS location column.
     */
    public function getLngAttribute(): ?float
    {
        if (! $this->location) {
            return null;
        }

        $result = \DB::selectOne('SELECT ST_X(location::geometry) as lng FROM events WHERE id = ?', [$this->id]);

        return $result?->lng ? (float) $result->lng : null;
    }

    /** @return BelongsTo<User, $this> */
    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organiser_id');
    }

    /** @return BelongsToMany<User, $this> */
    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_attendees')
            ->withPivot('joined_at', 'reminded_at')
            ->withTimestamps();
    }
}
