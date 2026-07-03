<?php

use App\Models\Spot;
use App\Services\NearbyPlaces;

test('km measures a known great-circle distance', function () {
    // Köln Hbf → Merkenich is ~13 km (the exact figure behind the origin bug).
    $km = NearbyPlaces::km(50.9433, 6.9583, 51.063, 6.966);

    expect($km)->toBeGreaterThan(12.0)->toBeLessThan(14.0);
});

test('nearest returns spots closest-first and honours the limit', function () {
    $near = Spot::factory()->create(['name' => 'Near', 'lat' => 50.9400, 'lng' => 6.9583]);
    $mid = Spot::factory()->create(['name' => 'Mid', 'lat' => 50.9600, 'lng' => 6.9583]);
    Spot::factory()->create(['name' => 'Far', 'lat' => 51.0600, 'lng' => 6.9583]);

    $result = app(NearbyPlaces::class)->nearest(50.9400, 6.9583, 2);

    expect($result)->toHaveCount(2)
        ->and($result->pluck('name')->all())->toBe(['Near', 'Mid']);
});

test('nearest can be narrowed to categories', function () {
    Spot::factory()->create(['name' => 'A café', 'category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    Spot::factory()->create(['name' => 'A park', 'category' => 'park', 'lat' => 50.941, 'lng' => 6.95]);

    $result = app(NearbyPlaces::class)->nearest(50.94, 6.95, 10, categories: ['park']);

    expect($result->pluck('name')->all())->toBe(['A park']);
});

test('withinKm keeps only rows inside the radius', function () {
    Spot::factory()->create(['name' => 'In', 'lat' => 50.9410, 'lng' => 6.9583]); // ~1.2km
    Spot::factory()->create(['name' => 'Out', 'lat' => 51.0600, 'lng' => 6.9583]); // ~13km

    $names = app(NearbyPlaces::class)
        ->withinKm(Spot::query(), 50.9400, 6.9583, 3.0)
        ->pluck('name')->all();

    expect($names)->toBe(['In']);
});

test('radiusForAtLeast widens until it has enough results', function () {
    // One spot near, the rest far: a 1km start can't reach 3, so it widens.
    Spot::factory()->create(['lat' => 50.9405, 'lng' => 6.9583]);
    Spot::factory()->create(['lat' => 51.0600, 'lng' => 6.9583]);
    Spot::factory()->create(['lat' => 51.0610, 'lng' => 6.9583]);

    $km = app(NearbyPlaces::class)->radiusForAtLeast(Spot::query(), 50.9400, 6.9583, 1.0, 3);

    expect($km)->toBeGreaterThan(1.0); // grew past the 1km start to reach 3
});
