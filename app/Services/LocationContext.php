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
    /** How recent a fix must be to say where the person is now ("Closest"). */
    public const FRESH_MINUTES = 10;

    public function __construct(
        public ?float $lat,
        public ?float $lng,
        public LocationSource $source,
        public ?string $label = null,
        /** When the position was fixed (unix seconds), when known. */
        public ?int $at = null,
    ) {}

    /** Whether this says where the person is now, not where they last were. */
    public function isFresh(): bool
    {
        return $this->source === LocationSource::Live
            || ($this->at !== null && now()->timestamp - $this->at <= self::FRESH_MINUTES * 60);
    }

    public function hasOrigin(): bool
    {
        return $this->source->isKnown() && $this->lat !== null && $this->lng !== null;
    }

    public function toGeoPoint(): ?GeoPoint
    {
        return $this->hasOrigin() ? new GeoPoint($this->lat, $this->lng) : null;
    }
}
