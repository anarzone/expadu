<?php

namespace App\Services;

use App\Enums\LocationSource;
use App\Transit\Dto\GeoPoint;

/**
 * The single resolved answer to "where is the user starting from?", shared by
 * the Places list and take-me-there so they can never disagree. When
 * {@see $source} is {@see LocationSource::None} there is deliberately no
 * origin — callers show a "set your location" affordance rather than measuring
 * from a guessed home/centre.
 */
final readonly class LocationContext
{
    public function __construct(
        public ?float $lat,
        public ?float $lng,
        public LocationSource $source,
        public ?string $label = null,
    ) {}

    public function hasOrigin(): bool
    {
        return $this->source->isKnown() && $this->lat !== null && $this->lng !== null;
    }

    public function toGeoPoint(): ?GeoPoint
    {
        return $this->hasOrigin() ? new GeoPoint($this->lat, $this->lng) : null;
    }
}
