<?php

use App\Composer\TravelEstimator;

it('walks short distances but uses a transit estimate for longer ones', function () {
    // Under 1.2 km → walking minutes (~14 min at 4.5 km/h for 1 km).
    expect(TravelEstimator::minutesFromKm(1.0))->toBeGreaterThan(10)->toBeLessThanOrEqual(15);

    // 10.86 km (Merkenich → Roggendorf/Thenhoven, across the Rhine) is a
    // ~40 min transit ride — NOT the 145 min walk the old formula produced.
    $far = TravelEstimator::minutesFromKm(10.86);
    expect($far)->toBeGreaterThan(30)->toBeLessThan(60);

    // A mis-geocoded point can't produce an absurd number — it's clamped.
    expect(TravelEstimator::minutesFromKm(500.0))->toBe(90);
});

it('keeps minutesBetween consistent with minutesFromKm', function () {
    $estimator = new TravelEstimator;
    // Cologne Dom → Deutz (~1.5 km straight line) should match the km helper.
    $km = $estimator->haversineKm(50.9413, 6.9583, 50.9494, 6.9747);

    expect($estimator->minutesBetween(50.9413, 6.9583, 50.9494, 6.9747))
        ->toBe(TravelEstimator::minutesFromKm($km));
});
