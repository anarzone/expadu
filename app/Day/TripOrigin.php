<?php

namespace App\Day;

use App\Transit\Dto\GeoPoint;
use Carbon\CarbonImmutable;

/**
 * Where the next trip starts and the earliest it can: the person's location
 * now, or the plan they'll be at until it ends.
 */
final readonly class TripOrigin
{
    public function __construct(
        public GeoPoint $point,
        public string $label,
        public string $source, // live | confirmed | ping | area | plan
        public ?CarbonImmutable $notBefore = null,
        public ?string $planId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'lat' => $this->point->lat,
            'lng' => $this->point->lng,
            'source' => $this->source,
            'plan_id' => $this->planId,
            'not_before' => $this->notBefore?->setTimezone('Europe/Berlin')->toIso8601String(),
        ];
    }
}
