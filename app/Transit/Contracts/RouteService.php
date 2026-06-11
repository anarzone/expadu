<?php

namespace App\Transit\Contracts;

use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;

/**
 * Provider-independent journey planning. The app never sees provider
 * formats — swapping providers is config, not surgery.
 */
interface RouteService
{
    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 3): JourneyResult;

    /**
     * @return list<Place>
     */
    public function geocode(string $query, ?GeoPoint $bias = null): array;

    public function reverseGeocode(GeoPoint $point): ?Place;
}
