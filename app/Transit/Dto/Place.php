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

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) ($data['name'] ?? ''),
            point: new GeoPoint((float) $data['lat'], (float) $data['lng']),
            stopId: $data['stop_id'] ?? null,
        );
    }
}
