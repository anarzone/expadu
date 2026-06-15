<?php

namespace App\Profile;

use App\Enums\GermanLevel;
use App\Enums\Situation;
use Carbon\CarbonImmutable;

/**
 * The resolved situation profile that every feature reads from. Built by
 * ProfileEngine from raw user attributes — never construct directly.
 */
final readonly class Profile
{
    public function __construct(
        public Situation $situation,
        public bool $isEu,
        public ?CarbonImmutable $arrivalDate,
        public ?string $veedel,
        public string $bureaucracyBranch,
        public TicketAdvice $ticketAdvice,
        /** @var list<string> Veedel names: the user's own + its Bezirk */
        public array $defaultAreas,
        public ?GermanLevel $germanLevel,
        /** @var array<string, mixed> Flat bag every applies_if is evaluated against */
        public array $attributes = [],
        /** @var list<string> user-picked interest keys (see Interest enum) */
        public array $interests = [],
    ) {}

    public function daysSinceArrival(): ?int
    {
        return $this->arrivalDate?->diffInDays(CarbonImmutable::now()->startOfDay());
    }
}
