<?php

namespace App\Transit\Dto;

final readonly class Place
{
    public function __construct(
        public string $name,
        public GeoPoint $point,
        public ?string $stopId = null,
    ) {}

    /**
     * @return array{name: string, lat: float, lng: float, stop_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'stop_id' => $this->stopId,
        ];
    }
}
