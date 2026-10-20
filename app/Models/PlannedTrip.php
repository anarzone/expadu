<?php

namespace App\Models;

use App\Transit\Dto\Journey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The way a person picked for a plan on their day (Plan a trip's "Add to
 * plan"): Leave now calls it "your 13:24" and leads with it while it still
 * leaves the margin; "Remind me when to leave" pushes five minutes before
 * it goes. One per plan; gone the day after.
 */
class PlannedTrip extends Model
{
    use MassPrunable;

    /** "Remind me when to leave": this long before the way leaves. */
    public const REMIND_BEFORE_MINUTES = 5;

    /**
     * Dates keep their offset on the way in: a way at 13:10 in Cologne is
     * stored as that instant, not as 13:10 UTC.
     */
    protected $dateFormat = 'Y-m-d H:i:sP';

    protected $fillable = [
        'user_id', 'date', 'target_id', 'title', 'journey', 'depart_at', 'arrive_at', 'remind', 'reminded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'journey' => 'array',
            'depart_at' => 'immutable_datetime',
            'arrive_at' => 'immutable_datetime',
            'remind' => 'boolean',
            'reminded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toJourney(): Journey
    {
        return Journey::fromArray($this->journey);
    }

    /** When the reminder goes: five minutes before the way leaves. */
    public function remindAt(): CarbonImmutable
    {
        return $this->depart_at->subMinutes(self::REMIND_BEFORE_MINUTES);
    }

    /**
     * The shape the day projection and Plan a trip read.
     *
     * @return array<string, mixed>
     */
    public function toShared(): array
    {
        $tz = 'Europe/Berlin';
        $journey = $this->journey;
        $ride = collect($journey['legs'] ?? [])->first(fn (array $leg) => ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true));

        return [
            'id' => $this->id,
            'target_id' => $this->target_id,
            'depart_at' => $this->depart_at->setTimezone($tz)->toIso8601String(),
            'depart_time' => $this->depart_at->setTimezone($tz)->format('H:i'),
            'arrive_time' => $this->arrive_at->setTimezone($tz)->format('H:i'),
            'ride' => $ride === null ? null : [
                'line' => $ride['line'] ?? null,
                'mode' => $ride['mode'],
                'time' => CarbonImmutable::parse($ride['depart_at'])->setTimezone($tz)->format('H:i'),
            ],
            'remind' => $this->remind,
            'remind_at' => $this->remindAt()->setTimezone($tz)->format('H:i'),
        ];
    }

    /** The day is over: the planned way goes (and with it what was stored). */
    public function prunable(): Builder
    {
        return static::query()->where('date', '<', now('Europe/Berlin')->subDay()->toDateString());
    }
}
