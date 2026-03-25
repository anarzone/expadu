<?php

namespace App\Models;

use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'emoji', 'category', 'description', 'starts_at', 'ends_at', 'location_name', 'address', 'max_attendees', 'is_free', 'price', 'organiser_id'])]
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
        ];
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
