<?php

use App\Composer\TravelEstimator;
use App\Enums\TransportMode;

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

it('gives distinct far distances distinct minutes instead of collapsing them', function () {
    // The bug: a flat 90-min cap made every walk beyond ~6.75 km read the same,
    // so a 8 km spot and a 15 km spot both showed "90 min away". Real distances
    // inside the metro must now stay distinct so the label — and any sort keyed
    // on it — tracks reality.
    $near = TravelEstimator::minutesFromKm(8.0, TransportMode::Walk);
    $far = TravelEstimator::minutesFromKm(15.0, TransportMode::Walk);

    expect($near)->toBeGreaterThan(90)      // no longer clamped to 90
        ->and($far)->toBeGreaterThan($near) // and the farther one reads farther
        ->and($far)->not->toBe($near);

    // A real cross-metro distance is a big honest number, not the mis-geocode cap.
    expect(TravelEstimator::minutesFromKm(30.0, TransportMode::Walk))->toBeGreaterThan(90);

    // But a coordinate outside greater Cologne is still capped as a data error.
    expect(TravelEstimator::minutesFromKm(80.0))->toBe(90);
});

it('keeps minutesBetween consistent with minutesFromKm', function () {
    $estimator = new TravelEstimator;
    // Cologne Dom → Deutz (~1.5 km straight line) should match the km helper.
    $km = $estimator->haversineKm(50.9413, 6.9583, 50.9494, 6.9747);

    expect($estimator->minutesBetween(50.9413, 6.9583, 50.9494, 6.9747))
        ->toBe(TravelEstimator::minutesFromKm($km));
});
