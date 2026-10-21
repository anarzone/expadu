<?php

namespace App\Personalisation;

use Carbon\CarbonImmutable;

/**
 * What the ledger has learned about one person, for ranking (R3c). Empty for
 * anyone who has not opted in, so every caller degrades to the
 * non-personalised order without a branch of its own.
 */
final readonly class AffinitySnapshot
{
    /**
     * @param  array<string, array<string, array<string, array{score: float, signals: int}>>>  $affinities  dimension → context → key → score
     * @param  array<string, array<int, array<string, float>>>  $placeNegatives  item type → id → context → score (≤ 0)
     * @param  array<string, array<int, CarbonImmutable>>  $hidden  item type → id → hidden until (only hides still in force)
     */
    public function __construct(
        public array $affinities = [],
        public array $placeNegatives = [],
        public array $hidden = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->affinities === [] && $this->placeNegatives === [] && $this->hidden === [];
    }

    /** dimension: category | veedel | price_band | daypart; context: all | weekday | weekend | companions:kids | weather:wet … */
    public function score(string $dimension, string $key, string $context = 'all'): float
    {
        return $this->affinities[$dimension][$context][$key]['score'] ?? 0.0;
    }

    public function placeScore(string $itemType, int $itemId, string $context = 'all'): float
    {
        return $this->placeNegatives[$itemType][$itemId][$context] ?? 0.0;
    }

    public function isHidden(string $itemType, int $itemId): bool
    {
        return isset($this->hidden[$itemType][$itemId]);
    }

    /** @return list<int> */
    public function hiddenIds(string $itemType): array
    {
        return array_keys($this->hidden[$itemType] ?? []);
    }
}
