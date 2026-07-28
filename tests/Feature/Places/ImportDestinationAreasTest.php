<?php

use App\Models\Spot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('the area importer attaches facilities to mapped parks and sports centres', function () {
    $park = Spot::factory()->create([
        'name' => 'Test Park',
        'category' => 'park',
        'source' => 'osm',
        'source_id' => 'way/101',
        'lat' => 50.9505,
        'lng' => 6.9505,
    ]);
    $sportsCentre = Spot::factory()->create([
        'name' => 'Sportanlage Test',
        'category' => 'sports_centre',
        'source' => 'osm',
        'source_id' => 'way/202',
        'lat' => 50.9605,
        'lng' => 6.9605,
    ]);
    $parkPitch = Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'lat' => 50.9504,
        'lng' => 6.9504,
    ]);
    $centreCourt = Spot::factory()->create([
        'name' => 'Tennisplatz',
        'category' => 'tennis',
        'lat' => 50.9604,
        'lng' => 6.9604,
    ]);
    $nearbyButSeparate = Spot::factory()->create([
        'name' => 'Separate basketball court',
        'category' => 'basketball',
        'lat' => 50.9704,
        'lng' => 6.9704,
    ]);

    Http::fake(['*' => Http::response(['elements' => [
        areaWay(101, 'Test Park', 'park', 50.95, 6.95),
        areaWay(202, 'Sportanlage Test', 'sports_centre', 50.96, 6.96),
    ]])]);

    $this->artisan('parks:import-areas')->assertSuccessful();

    expect($parkPitch->fresh())
        ->parent_spot_id->toBe($park->id)
        ->park_name->toBe('Test Park')
        ->and($centreCourt->fresh())
        ->parent_spot_id->toBe($sportsCentre->id)
        ->park_name->toBe('Sportanlage Test')
        ->and($nearbyButSeparate->fresh())
        ->parent_spot_id->toBeNull()
        ->and(DB::table('park_areas')->where('source_id', 'way/202')->value('kind'))
        ->toBe('sports_centre');
});

/**
 * @return array<string, mixed>
 */
function areaWay(int $id, string $name, string $leisure, float $lat, float $lng): array
{
    return [
        'type' => 'way',
        'id' => $id,
        'tags' => ['name' => $name, 'leisure' => $leisure, 'sport' => 'tennis'],
        'geometry' => [
            ['lat' => $lat, 'lon' => $lng],
            ['lat' => $lat, 'lon' => $lng + 0.001],
            ['lat' => $lat + 0.001, 'lon' => $lng + 0.001],
            ['lat' => $lat + 0.001, 'lon' => $lng],
            ['lat' => $lat, 'lon' => $lng],
        ],
    ];
}
