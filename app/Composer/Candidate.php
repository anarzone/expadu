<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * One plannable thing: a spot (flexible timing), a curated event (fixed
 * start), or the user's own booked appointment (fixed start, immovable).
 * Snapshot of everything the pure pipeline needs so no stage touches the
 * database.
 */
final readonly class Candidate
{
    public function __construct(
        public string $id,         // "spot:12" | "event:34" | "appointment:7"
        public string $type,       // spot | event | appointment
        public string $name,
        public float $lat,
        public float $lng,
        public ?string $veedel,
        public string $category,
        public bool $outdoor,
        public int $typicalDurationMin,
        public string $costTier,           // free | low | normal
        public ?CarbonImmutable $opensAt,  // null = always open within window
        public ?CarbonImmutable $closesAt,
        public ?CarbonImmutable $fixedStart = null, // events + appointments
        public bool $swappable = true,              // false for appointments
        public ?string $subtitle = null,            // e.g. office + documents line
        public bool $isLandmark = false,            // notable (OSM wikidata/wikipedia) → hero pick
        public bool $closedToday = false,           // real opening hours say shut on the plan's day
    ) {}

    public function isFixedTime(): bool
    {
        return $this->fixedStart !== null;
    }

    public function isAppointment(): bool
    {
        return $this->type === 'appointment';
    }
}
