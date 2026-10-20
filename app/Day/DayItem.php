<?php

namespace App\Day;

use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * One thing on a person's day: a recorded Paperwork appointment or a plan they
 * confirmed in Composer. What isn't recorded stays unknown here — an unknown
 * end is null (never a guessed duration) and a text-only place carries no
 * coordinates, so nothing downstream can route to an invented point.
 */
final readonly class DayItem
{
    public function __construct(
        public string $id,
        public string $kind, // appointment | plan | event
        public string $source, // paperwork | workspace
        public string $title,
        public ?CarbonImmutable $startAt,
        public ?CarbonImmutable $endAt,
        // An official appointment (Paperwork) wants ten minutes in hand, not five.
        public bool $official = false,
        public ?string $locationLabel = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?CarbonImmutable $completedAt = null,
        // Fixed time: an event or appointment can't be moved by the person.
        public bool $fixed = false,
        // Only confirmed Composer plans take Mark as done; Paperwork keeps its own state.
        public bool $completable = false,
        // False when Composer's catalogue says a saved place/event needs review.
        public bool $available = true,
        public ?string $category = null,
        public ?string $subtitle = null,
    ) {}

    public function routable(): bool
    {
        return $this->lat !== null && $this->lng !== null
            && is_finite($this->lat) && is_finite($this->lng)
            && abs($this->lat) <= 90 && abs($this->lng) <= 180
            && ! ($this->lat === 0.0 && $this->lng === 0.0);
    }

    public function point(): ?GeoPoint
    {
        return $this->routable() ? new GeoPoint($this->lat, $this->lng) : null;
    }

    public function completed(): bool
    {
        return $this->completedAt !== null;
    }

    /**
     * Where the item stands at $now. An item with no recorded end that has
     * started reads "started": it may still be going on, or it may be over.
     */
    public function state(CarbonImmutable $now): string
    {
        if ($this->startAt === null || $now->lessThan($this->startAt)) {
            return 'upcoming';
        }
        if ($this->endAt === null) {
            return 'started';
        }

        return $now->lessThan($this->endAt) ? 'ongoing' : 'elapsed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(CarbonImmutable $now): array
    {
        $tz = 'Europe/Berlin';
        $start = $this->startAt?->setTimezone($tz);
        $end = $this->endAt?->setTimezone($tz);

        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->source,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'category' => $this->category,
            'official' => $this->official,
            'fixed' => $this->fixed,
            'start_at' => $start?->toIso8601String(),
            'end_at' => $end?->toIso8601String(),
            'start_time' => $start?->format('H:i'),
            'end_time' => $end?->format('H:i'),
            'duration_known' => $end !== null,
            'location' => $this->locationLabel === null && ! $this->routable() ? null : [
                'label' => $this->locationLabel,
                'lat' => $this->routable() ? $this->lat : null,
                'lng' => $this->routable() ? $this->lng : null,
                'routable' => $this->routable(),
            ],
            'state' => $this->state($now),
            'completed' => $this->completed(),
            'completed_at' => $this->completedAt?->setTimezone($tz)->toIso8601String(),
            'completable' => $this->completable,
            'available' => $this->available,
        ];
    }
}
