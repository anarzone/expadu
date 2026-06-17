<?php

namespace App\Transit\Dto;

final readonly class Place
{
    public function __construct(
        public string $name,
        public GeoPoint $point,
        public ?string $stopId = null,
        // Municipality (OSM admin level 6, e.g. "Köln") — drives the
        // Rheinlandtarif Preisstufe. Populated by reverse-geocode.
        public ?string $municipality = null,
    ) {}

    /**
     * @return array{name: string, lat: float, lng: float, stop_id: ?string, municipality: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'stop_id' => $this->stopId,
            'municipality' => $this->municipality,
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
            municipality: $data['municipality'] ?? null,
        );
    }
}
