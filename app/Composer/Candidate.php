<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * One plannable thing: a spot (flexible timing) or a curated event
 * (fixed start). Snapshot of everything the pure pipeline needs so no
 * stage touches the database.
 */
final readonly class Candidate
{
    public function __construct(
        public string $id,         // "spot:12" | "event:34"
        public string $type,       // spot | event
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
        public ?CarbonImmutable $fixedStart = null, // events only
    ) {}

    public function isFixedTime(): bool
    {
        return $this->fixedStart !== null;
    }
}
