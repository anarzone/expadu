<?php

namespace App\Composer;

use App\Composer\Contracts\EstimatesTravel;
use App\Enums\TransportMode;

/**
 * Pure travel-time heuristic for plan composition. Walk under 1.2 km,
 * transit above (18 km/h effective + 7 min stop/wait overhead). Real
 * journeys come from RouteService only when the user taps "take me
 * there" on a slot — never during composition.
 */
class TravelEstimator implements EstimatesTravel
{
    private const WALK_KMH = 4.5;

    private const BIKE_KMH = 14.0;

    private const TRANSIT_KMH = 18.0;

    private const TRANSIT_OVERHEAD_MIN = 7;

    private const WALK_MAX_KM = 1.2;

    /** Nothing in Cologne is further than this; beyond it the input coords
     *  are bad (a mis-geocoded venue), so clamp rather than show 1500 min. */
    private const MAX_MIN = 90;

    public function minutesBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        return self::minutesFromKm($this->haversineKm($fromLat, $fromLng, $toLat, $toLng));
    }

    /**
     * Straight-line travel minutes — the Places "X min away" proxy when MOTIS
     * has no real route. When the user's transport mode is known it's honoured
     * so the label tracks the From-bar toggle (walk reads slower than bike);
     * without a mode it keeps the composer's distance-tiered model (walk under
     * 1.2 km, transit above). Real journeys come from MOTIS on "take me there".
     */
    public static function minutesFromKm(float $km, ?TransportMode $mode = null): int
    {
        $minutes = match ($mode) {
            TransportMode::Walk => max(1, (int) ceil($km / self::WALK_KMH * 60)),
            TransportMode::Bike => max(1, (int) ceil($km / self::BIKE_KMH * 60)),
            // transit / unset → the composer's distance-tiered model (unchanged).
            default => $km <= self::WALK_MAX_KM
                ? max(1, (int) ceil($km / self::WALK_KMH * 60))
                : self::TRANSIT_OVERHEAD_MIN + (int) ceil($km / self::TRANSIT_KMH * 60),
        };

        return min(self::MAX_MIN, $minutes);
    }

    public function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
