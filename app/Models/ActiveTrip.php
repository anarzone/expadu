<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The journey a user is currently travelling. Persisted so an in-progress trip
 * survives an app close and is surfaced on every screen (the "trip in progress"
 * banner) until the user ends it. One row per user.
 */
class ActiveTrip extends Model
{
    protected $fillable = [
        'user_id', 'journey', 'origin', 'destination', 'started_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'journey' => 'array',
            'origin' => 'array',
            'destination' => 'array',
            'started_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The shape shared with the frontend (the app-wide banner + the planner's
     * live view read this).
     *
     * @return array{journey: mixed, origin: mixed, destination: mixed, started_at: ?string}
     */
    public function toShared(): array
    {
        return [
            'journey' => $this->journey,
            'origin' => $this->origin,
            'destination' => $this->destination,
            'started_at' => $this->started_at?->toIso8601String(),
        ];
    }
}
