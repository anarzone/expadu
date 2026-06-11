<?php

namespace App\Transit\Dto;

/**
 * The normalized result every provider adapter returns. `source` tells the
 * frontend which path served (and whether to show the degraded UI).
 */
final readonly class JourneyResult
{
    /**
     * @param  list<Journey>  $journeys
     * @param  array{departures: array<int, mixed>, deep_links: array<string, string>}|null  $degraded
     */
    public function __construct(
        public array $journeys,
        public string $source, // transitous | trias | degraded
        public ?array $degraded = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'journeys' => array_map(fn (Journey $j) => $j->toArray(), $this->journeys),
            'degraded' => $this->degraded,
        ];
    }
}
