<?php

use App\Models\Spot;
use Illuminate\Support\Facades\Http;

test('osm:import creates spots from overpass api response', function () {
    Http::fake([
        'overpass-api.de/*' => Http::response([
            'elements' => [
                [
                    'id' => 123456,
                    'lat' => 50.9478,
                    'lon' => 6.9183,
                    'tags' => [
                        'name' => 'Test Café',
                        'amenity' => 'cafe',
                        'addr:street' => 'Venloer Str.',
                        'addr:housenumber' => '42',
                        'addr:city' => 'Köln',
                    ],
                ],
                [
                    'id' => 789012,
                    'lat' => 50.9467,
                    'lon' => 6.9417,
                    'tags' => [
                        'name' => 'Test Coworking',
                        'amenity' => 'coworking_space',
                    ],
                ],
                [
                    'id' => 345678,
                    'lat' => 50.9339,
                    'lon' => 6.9479,
                    'tags' => [
                        'name' => 'Test Library',
                        'amenity' => 'library',
                        'addr:street' => 'Main Str.',
                        'addr:city' => 'Köln',
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('osm:import --city=cologne')
        ->assertSuccessful();

    expect(Spot::where('name', 'Test Café')->exists())->toBeTrue();
    expect(Spot::where('name', 'Test Coworking')->exists())->toBeTrue();
    expect(Spot::where('name', 'Test Library')->exists())->toBeTrue();

    $cafe = Spot::where('name', 'Test Café')->first();
    expect($cafe->category->value)->toBe('cafe');
    expect($cafe->address)->toBe('Venloer Str. 42, Köln');
    expect((float) $cafe->lat)->toBe(50.9478);
    expect((float) $cafe->lng)->toBe(6.9183);

    $coworking = Spot::where('name', 'Test Coworking')->first();
    expect($coworking->category->value)->toBe('coworking');

    $library = Spot::where('name', 'Test Library')->first();
    expect($library->category->value)->toBe('library');
});

test('osm:import skips elements without a name', function () {
    Http::fake([
        'overpass-api.de/*' => Http::response([
            'elements' => [
                [
                    'id' => 111,
                    'lat' => 50.94,
                    'lon' => 6.91,
                    'tags' => [
                        'amenity' => 'cafe',
                    ],
                ],
                [
                    'id' => 222,
                    'lat' => 50.95,
                    'lon' => 6.92,
                    'tags' => [
                        'name' => 'Named Café',
                        'amenity' => 'cafe',
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('osm:import --city=cologne')
        ->assertSuccessful();

    expect(Spot::count())->toBe(1);
    expect(Spot::first()->name)->toBe('Named Café');
});

test('osm:import skips duplicates within 50m', function () {
    // Pre-create a spot
    Spot::create([
        'name' => 'Existing Café',
        'category' => 'cafe',
        'lat' => 50.9478,
        'lng' => 6.9183,
    ]);

    Http::fake([
        'overpass-api.de/*' => Http::response([
            'elements' => [
                [
                    'id' => 333,
                    'lat' => 50.9478,
                    'lon' => 6.9183,
                    'tags' => [
                        'name' => 'Existing Café',
                        'amenity' => 'cafe',
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('osm:import --city=cologne')
        ->assertSuccessful();

    expect(Spot::where('name', 'Existing Café')->count())->toBe(1);
});

test('osm:import rejects unsupported city', function () {
    $this->artisan('osm:import --city=berlin')
        ->assertFailed();
});

test('osm:import resolves coworking from office tag', function () {
    Http::fake([
        'overpass-api.de/*' => Http::response([
            'elements' => [
                [
                    'id' => 444,
                    'lat' => 50.94,
                    'lon' => 6.91,
                    'tags' => [
                        'name' => 'Office Coworking',
                        'office' => 'coworking',
                    ],
                ],
            ],
        ]),
    ]);

    $this->artisan('osm:import --city=cologne')
        ->assertSuccessful();

    expect(Spot::first()->category->value)->toBe('coworking');
});
