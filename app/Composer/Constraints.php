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
    ) {}

    public function windowMinutes(): int
    {
        return max(0, (int) $this->windowStart->diffInMinutes($this->windowEnd));
    }

    /** Same constraints with the budget cap lifted — used when a filter combination leaves nothing feasible. */
    public function withoutBudget(): self
    {
        return new self($this->windowStart, $this->windowEnd, $this->areas, $this->categories, $this->companions, null, $this->archetype, $this->vibe);
    }

    /** Same constraints with the category filter lifted — the deepest relaxation before giving up. */
    public function withoutCategories(): self
    {
        return new self($this->windowStart, $this->windowEnd, $this->areas, [], $this->companions, $this->budget, $this->archetype, $this->vibe);
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
        );
    }
}
