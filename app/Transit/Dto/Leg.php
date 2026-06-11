<?php

namespace App\Transit\Dto;

use Carbon\CarbonImmutable;

final readonly class Leg
{
    public function __construct(
        public string $mode, // walk | bus | tram | subway | rail | ferry
        public Place $from,
        public Place $to,
        public CarbonImmutable $departAt,
        public CarbonImmutable $arriveAt,
        public int $durationMin,
        public ?string $lineName = null,
        public ?string $headsign = null,
        public ?string $polyline = null,
    ) {}

    public function isTransit(): bool
    {
        return $this->mode !== 'walk';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'line' => $this->lineName,
            'headsign' => $this->headsign,
            'from' => $this->from->toArray(),
            'to' => $this->to->toArray(),
            'depart_at' => $this->departAt->toIso8601String(),
            'arrive_at' => $this->arriveAt->toIso8601String(),
            'depart_time' => $this->departAt->format('H:i'),
            'arrive_time' => $this->arriveAt->format('H:i'),
            'duration_min' => $this->durationMin,
            'polyline' => $this->polyline,
        ];
    }
}
