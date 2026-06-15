<?php

namespace App\Composer;

/**
 * Everything impure the scorer needs, snapshotted once per composition:
 * weather, profile areas, and the user's intent-signal weights.
 */
final readonly class ScoringContext
{
    /**
     * @param  list<string>  $preferredAreas  Veedel names from constraints or profile
     * @param  array<string, float>  $intentWeights  "category|veedel" → weight
     */
    /**
     * @param  list<string>  $pinnedIds  candidate ids the user chose to plan around
     */
    public function __construct(
        public bool $rainExpected,
        public array $preferredAreas,
        public array $intentWeights = [],
        public ?string $companions = null, // alone | partner | friends | kids
        public array $pinnedIds = [],
        /** @var array<string, float> category => situation/interest affinity */
        public array $affinity = [],
    ) {}
}
