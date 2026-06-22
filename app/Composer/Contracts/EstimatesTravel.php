<?php

namespace App\Composer\Contracts;

use App\Composer\MatrixTravelEstimator;
use App\Composer\TravelEstimator;

/**
 * A travel-time estimate between two points, in minutes. Implementations are a
 * pure heuristic ({@see TravelEstimator}) or matrix-backed
 * ({@see MatrixTravelEstimator}).
 */
interface EstimatesTravel
{
    public function minutesBetween(float $fromLat, float $fromLng, float $toLat, float $toLng): int;
}
