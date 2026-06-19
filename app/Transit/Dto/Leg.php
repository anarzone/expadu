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
        public ?int $stopsCount = null, // stops ridden until get-off (transit legs)
    ) {}

    public function isTransit(): bool
    {
        return $this->mode !== 'walk' && $this->mode !== 'bike';
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
            'stops' => $this->stopsCount,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) $data['mode'],
            from: Place::fromArray($data['from']),
            to: Place::fromArray($data['to']),
            departAt: CarbonImmutable::parse($data['depart_at']),
            arriveAt: CarbonImmutable::parse($data['arrive_at']),
            durationMin: (int) $data['duration_min'],
            lineName: $data['line'] ?? null,
            headsign: $data['headsign'] ?? null,
            polyline: $data['polyline'] ?? null,
            stopsCount: isset($data['stops']) ? (int) $data['stops'] : null,
        );
    }
}
