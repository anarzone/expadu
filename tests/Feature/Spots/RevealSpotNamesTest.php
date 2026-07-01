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

test('with --geocode, a bare spot in one of the 3 cities is anchored to its nearest street', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'park_name' => null,
        'lat' => 50.98,
        'lng' => 6.99,
    ]);

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('reverseGeocode')->andReturn(new Place(
            name: 'Merkenicher Hauptstraße 5, 50769 Köln',
            point: new GeoPoint(50.98, 6.99),
            municipality: 'Köln',
        ));
    });

    $this->artisan('spots:reveal-names --geocode')->assertSuccessful();

    // house number + city dropped, street kept.
    expect($spot->fresh()->name)->toBe('Bolzplatz · Merkenicher Hauptstraße');
});

test('a spot whose city is not Köln / Leverkusen / Bonn is pruned', function () {
    $spot = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'park_name' => null,
        'lat' => 50.9,
        'lng' => 6.8,
    ]);

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('reverseGeocode')->andReturn(new Place(
            name: 'Hauptstraße, Frechen',
            point: new GeoPoint(50.9, 6.8),
            municipality: 'Frechen',
        ));
    });

    $this->artisan('spots:reveal-names --geocode')->assertSuccessful();

    expect(Spot::find($spot->id))->toBeNull();
});

test('a nearest POI (not a street) leaves the spot bare rather than mislabelled', function () {
    $spot = Spot::factory()->create([
        'name' => 'Spielplatz',
        'category' => 'playground',
        'park_name' => null,
        'lat' => 50.94,
        'lng' => 6.95,
    ]);

    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('reverseGeocode')->andReturn(new Place(
            name: 'Eiscafe Linizio',
            point: new GeoPoint(50.94, 6.95),
            municipality: 'Köln',
        ));
    });

    $this->artisan('spots:reveal-names --geocode')->assertSuccessful();

    expect($spot->fresh()->name)->toBe('Spielplatz');
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
