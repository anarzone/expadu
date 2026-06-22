<?php

namespace App\Transit;

use App\Enums\TransportMode;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;

/**
 * Turns a user's transport-mode preference into per-destination travel
 * minutes, applying the "fastest realistic" policy.
 *
 * Bike is the fastest street mode the one-to-many matrix can compute and the
 * at-a-glance proxy for transit (the matrix can't route transit per
 * destination — exact transit times come from a full plan in take-me-there).
 * Walking is only realistic up to {@see self::WALK_GUARD_MIN} minutes; past
 * that a destination falls back to its bike time so we never show a four-hour
 * walk.
 */
class TravelTimes
{
    private const WALK_GUARD_MIN = 45;

    public function __construct(private readonly RouteService $routes) {}

    /**
     * Per-destination minutes in the SAME ORDER as $destinations; null where
     * unreachable. Unset preference, transit, and bike all resolve to the bike
     * matrix (the fastest street time we can compute per destination).
     *
     * @param  list<GeoPoint>  $destinations
     * @return list<int|null>
     */
    public function minutes(?TransportMode $mode, GeoPoint $origin, array $destinations): array
    {
        if ($destinations === []) {
            return [];
        }

        if ($mode === TransportMode::Walk) {
            return $this->walkWithGuard($origin, $destinations);
        }

        return $this->routes->travelMatrix($origin, $destinations, 'BIKE');
    }

    /**
     * Walk times, but anything beyond a realistic walk (or unreachable on foot)
     * falls back to the bike time for that destination.
     *
     * @param  list<GeoPoint>  $destinations
     * @return list<int|null>
     */
    private function walkWithGuard(GeoPoint $origin, array $destinations): array
    {
        $walk = $this->routes->travelMatrix($origin, $destinations, 'WALK');

        $needsBike = false;
        foreach ($destinations as $i => $destination) {
            $minutes = $walk[$i] ?? null;
            if ($minutes === null || $minutes > self::WALK_GUARD_MIN) {
                $needsBike = true;
                break;
            }
        }

        if (! $needsBike) {
            return $walk;
        }

        $bike = $this->routes->travelMatrix($origin, $destinations, 'BIKE');

        $out = [];
        foreach ($destinations as $i => $destination) {
            $minutes = $walk[$i] ?? null;
            $out[] = ($minutes !== null && $minutes <= self::WALK_GUARD_MIN)
                ? $minutes
                : ($bike[$i] ?? $minutes);
        }

        return $out;
    }
}
