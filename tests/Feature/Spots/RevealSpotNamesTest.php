<?php

use App\Models\Spot;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Place;

test('a bare facility inside a park is anchored to the park name', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'park_name' => 'Fühlinger Park',
    ]);

    $this->artisan('spots:reveal-names')->assertSuccessful();

    expect($spot->fresh()->name)->toBe('Bolzplatz · Fühlinger Park');
});

test('a real OSM name is never rewritten', function () {
    $spot = Spot::factory()->create([
        'name' => 'Rheinwiesen',
        'category' => 'park',
        'park_name' => 'Fühlinger Park',
    ]);

    $this->artisan('spots:reveal-names')->assertSuccessful();

    expect($spot->fresh()->name)->toBe('Rheinwiesen');
});

test('without --geocode, spots not in a park stay bare', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'park_name' => null,
    ]);

    $this->artisan('spots:reveal-names')->assertSuccessful();

    expect($spot->fresh()->name)->toBe('Bolzplatz');
});

test('with --geocode, a bare spot not in a park is anchored to its nearest street', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'park_name' => null,
        'lat' => 51.0,
        'lng' => 6.95,
    ]);

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('reverseGeocode')
            ->andReturn(new Place('Merkenicher Hauptstraße 5, 50769 Köln', new GeoPoint(51.0, 6.95)));
    });

    $this->artisan('spots:reveal-names --geocode')->assertSuccessful();

    // house number + city dropped, street kept.
    expect($spot->fresh()->name)->toBe('Bolzplatz · Merkenicher Hauptstraße');
});

test('the pass is idempotent — an already-anchored spot is not touched again', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz · Fühlinger Park',
        'category' => 'pitch',
        'park_name' => 'Fühlinger Park',
    ]);

    $this->artisan('spots:reveal-names')->assertSuccessful();

    expect($spot->fresh()->name)->toBe('Bolzplatz · Fühlinger Park');
});
