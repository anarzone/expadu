<?php

namespace App\Models;

use App\Transit\DepartureTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The journey a user is currently travelling. Persisted so an in-progress trip
 * survives an app close and is surfaced on every screen (the "trip in progress"
 * banner) until the user ends it. One row per user.
 */
class ActiveTrip extends Model
{
    use MassPrunable;

    /**
     * A trip is over this long after its arrival: the trip page has said
     * "Arrived", and the row (with where it started) goes. Location is kept
     * only while the trip needs it.
     */
    public const OVER_AFTER_ARRIVAL_MINUTES = 120;

    /** Nothing in the service area takes a day; older rows are pruned nightly. */
    public const PRUNE_AFTER_HOURS = 24;

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

    /** Over: arrived long enough ago, started more than a day ago, or unreadable. */
    public function isOver(): bool
    {
        $arrive = DepartureTime::parse($this->journey['arrive_at'] ?? null);

        return $arrive === null
            || $arrive->addMinutes(self::OVER_AFTER_ARRIVAL_MINUTES)->isPast()
            || ($this->started_at !== null && $this->started_at->lt(now()->subHours(self::PRUNE_AFTER_HOURS)));
    }

    /**
     * The person's trip if it's still running; a finished one is deleted on
     * the way, so its origin isn't kept.
     */
    public static function currentFor(?User $user): ?self
    {
        $trip = $user?->activeTrip;
        if ($trip !== null && $trip->isOver()) {
            $trip->delete();

            return null;
        }

        return $trip;
    }

    /** @return Builder<self> */
    public function prunable(): Builder
    {
        return static::query()->where('started_at', '<', now()->subHours(self::PRUNE_AFTER_HOURS));
    }
}
