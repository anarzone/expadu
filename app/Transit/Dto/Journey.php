<?php

namespace App\Transit\Dto;

use Carbon\CarbonImmutable;

final readonly class Journey
{
    /**
     * @param  list<Leg>  $legs
     */
    public function __construct(
        public array $legs,
        public CarbonImmutable $departAt,
        public CarbonImmutable $arriveAt,
        public int $durationMin,
        public int $transfers,
    ) {}

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter(array_map(
            fn (Leg $leg) => $leg->lineName,
            $this->legs,
        )));
    }

    /**
     * Primary mode for the UI's mode switcher: transit when any leg rides
     * transit; otherwise a single-leg direct route — bike or walk.
     */
    public function mode(): string
    {
        foreach ($this->legs as $leg) {
            if ($leg->isTransit()) {
                return 'transit';
            }
        }

        foreach ($this->legs as $leg) {
            if ($leg->mode === 'bike') {
                return 'bike';
            }
        }

        return 'walk';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'depart_at' => $this->departAt->toIso8601String(),
            'arrive_at' => $this->arriveAt->toIso8601String(),
            'depart_time' => $this->departAt->format('H:i'),
            'arrive_time' => $this->arriveAt->format('H:i'),
            'duration_min' => $this->durationMin,
            'transfers' => $this->transfers,
            'mode' => $this->mode(),
            'lines' => $this->lines(),
            'legs' => array_map(fn (Leg $leg) => $leg->toArray(), $this->legs),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            legs: array_map(fn (array $l) => Leg::fromArray($l), $data['legs'] ?? []),
            departAt: CarbonImmutable::parse($data['depart_at']),
            arriveAt: CarbonImmutable::parse($data['arrive_at']),
            durationMin: (int) $data['duration_min'],
            transfers: (int) $data['transfers'],
        );
    }
}
