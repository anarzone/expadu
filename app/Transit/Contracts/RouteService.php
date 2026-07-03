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
    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false): JourneyResult;

    /**
     * @return list<Place>
     */
    public function geocode(string $query, ?GeoPoint $bias = null): array;

    public function reverseGeocode(GeoPoint $point): ?Place;

    /**
     * Street-network travel minutes from one origin to many destinations, in
     * the SAME ORDER as $destinations. A destination that is unreachable, off
     * the network, or beyond the engine's reach is null — callers fall back to
     * their own estimate. Best-effort: an all-null result means "unavailable".
     *
     * @param  list<GeoPoint>  $destinations
     * @param  'WALK'|'BIKE'|'CAR'  $mode
     * @return list<int|null>
     */
    public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array;
}
