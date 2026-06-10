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
    ) {}

    public function daysSinceArrival(): ?int
    {
        return $this->arrivalDate?->diffInDays(CarbonImmutable::now()->startOfDay());
    }
}
