<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * The structured constraint object the whole composer pipeline runs on.
 * Produced by the LLM parser (which only parses, never picks) or built
 * from profile defaults; always shown back to the user as editable chips.
 */
final readonly class Constraints
{
    /**
     * @param  list<string>  $categories  empty = all categories
     * @param  list<string>  $areas  Veedel names; empty = user's default areas
     */
    public function __construct(
        public CarbonImmutable $windowStart,
        public CarbonImmutable $windowEnd,
        public array $areas = [],
        public array $categories = [],
        public ?string $companions = null, // alone | partner | friends | kids
        public ?string $budget = null,     // free | low | normal
        public ?Archetype $archetype = null, // day shape; null = Balanced
        public ?string $vibe = null,         // chill | active
        /** @var list<string> */
        public array $activities = [],
        public ?float $radiusKm = null,
        /** @var list<string> mood words read from the request (Mood values); soft, they rank */
        public array $moods = [],
        /** @var list<string> categories the request rules out ("not a loud club"); hard */
        public array $avoid = [],
        /** @var list<string> stops in the order asked ("museum, then coffee"); each a category or selector */
        public array $sequence = [],
        /** @var list<string> hard needs (Need values), e.g. step_free; places without the verified fact drop out */
        public array $needs = [],
    ) {}

    /** Mood words the request may carry; soft preferences for ranking and the judge. */
    public const MOODS = ['quiet', 'lively', 'cosy', 'relaxed', 'active', 'romantic', 'cultural', 'social', 'scenic', 'local'];

    /** Hard needs a place must meet with a verified fact. */
    public const NEEDS = ['step_free'];

    /**
     * The same constraints with some fields replaced.
     *
     * @param  array<string, mixed>  $changes  constructor argument names
     */
    public function with(array $changes): self
    {
        return new self(...[...get_object_vars($this), ...$changes]);
    }

    public function windowMinutes(): int
    {
        return max(0, (int) $this->windowStart->diffInMinutes($this->windowEnd));
    }

    /** Same constraints with the budget cap lifted — used when a filter combination leaves nothing feasible. */
    public function withoutBudget(): self
    {
        return $this->with(['budget' => null]);
    }

    /** Same constraints with the category filter lifted — the deepest relaxation before giving up. */
    public function withoutCategories(): self
    {
        return $this->with(['categories' => [], 'sequence' => []]);
    }

    /**
     * Same constraints with a different category set — used to widen a solo
     * activity to its day complements so the itinerary isn't six of one thing.
     *
     * @param  list<string>  $categories
     */
    public function withCategories(array $categories): self
    {
        return $this->with(['categories' => array_values($categories)]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'window_start' => $this->windowStart->toIso8601String(),
            'window_end' => $this->windowEnd->toIso8601String(),
            'areas' => $this->areas,
            'categories' => $this->categories,
            'companions' => $this->companions,
            'budget' => $this->budget,
            'archetype' => $this->archetype?->value,
            'vibe' => $this->vibe,
            'activities' => $this->activities,
            'radius_km' => $this->radiusKm,
            'moods' => $this->moods,
            'avoid' => $this->avoid,
            'sequence' => $this->sequence,
            'needs' => $this->needs,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            windowStart: CarbonImmutable::parse($data['window_start'])->setTimezone('Europe/Berlin'),
            windowEnd: CarbonImmutable::parse($data['window_end'])->setTimezone('Europe/Berlin'),
            areas: array_values((array) ($data['areas'] ?? [])),
            categories: array_values((array) ($data['categories'] ?? [])),
            companions: $data['companions'] ?? null,
            budget: $data['budget'] ?? null,
            archetype: isset($data['archetype']) ? Archetype::tryFrom((string) $data['archetype']) : null,
            vibe: $data['vibe'] ?? null,
            activities: array_values((array) ($data['activities'] ?? [])),
            radiusKm: isset($data['radius_km']) ? (float) $data['radius_km'] : null,
            moods: array_values(array_intersect((array) ($data['moods'] ?? []), self::MOODS)),
            avoid: array_values((array) ($data['avoid'] ?? [])),
            sequence: array_values((array) ($data['sequence'] ?? [])),
            needs: array_values(array_intersect((array) ($data['needs'] ?? []), self::NEEDS)),
        );
    }
}
