<?php

namespace App\Composer;

use App\Composer\Contracts\EstimatesTravel;

/**
 * Real one-to-many street minutes from the plan's origin — the leg most likely
 * to be long and mode-sensitive ("from where you are to your first stop") —
 * with the haversine heuristic for every inter-stop hop (short, intra-area) and
 * whenever the matrix has no value for a destination. Composition evaluates
 * hundreds of candidate placements at a moving cursor, so only the origin leg
 * is worth a real route; the rest stays a cheap estimate.
 */
class MatrixTravelEstimator implements EstimatesTravel
{
    /**
     * @param  array<string, int>  $originMinutes  real minutes keyed by coordKey()
     */
    public function __construct(
        private readonly TravelEstimator $fallback,
        private readonly float $originLat,
        private readonly float $originLng,
        private readonly array $originMinutes,
    ) {}

    public function minutesBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): int
    {
        if ($this->isOrigin($fromLat, $fromLng)) {
            $real = $this->originMinutes[self::coordKey($toLat, $toLng)] ?? null;
            if ($real !== null) {
                return $real;
            }
        }

        return $this->fallback->minutesBetween($fromLat, $fromLng, $toLat, $toLng);
    }

    public static function coordKey(float $lat, float $lng): string
    {
        return round($lat, 5).','.round($lng, 5);
    }

    private function isOrigin(float $lat, float $lng): bool
    {
        return abs($lat - $this->originLat) < 1e-6 && abs($lng - $this->originLng) < 1e-6;
    }
}
