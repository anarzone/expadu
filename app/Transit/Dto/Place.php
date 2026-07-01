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
        // Geocoder hit kind: stop | address | place. Lets search UIs show a
        // station glyph vs a street pin. Populated by geocode.
        public ?string $kind = null,
        // Human context for disambiguation, e.g. "Bickendorf · Köln".
        public ?string $area = null,
    ) {}

    /**
     * @return array{name: string, lat: float, lng: float, stop_id: ?string, municipality: ?string, kind: ?string, area: ?string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'stop_id' => $this->stopId,
            'municipality' => $this->municipality,
            'kind' => $this->kind,
            'area' => $this->area,
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
            kind: $data['kind'] ?? null,
            area: $data['area'] ?? null,
        );
    }
}
