<?php

use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Illuminate\Support\Facades\DB;

function publicUsePitch(array $observation, array $spot = []): array
{
    $spot = Spot::factory()->create(['category' => 'pitch', 'source' => 'osm', 'source_id' => 'way/'.random_int(1_000_000, 9_999_999), 'tags' => [], 'lat' => 50.94, 'lng' => 6.95] + $spot);
    app(RecordPlaceObservation::class)->record($spot, $observation + [
        'provider_record_id' => $spot->source_id, 'observed_at' => now()->subDay()->toIso8601String(), 'ingestion_key' => 'public-use:'.$spot->id,
    ]);

    return app(PlaceFacts::class)->resolve($spot->fresh())['public_use'];
}

test("the city's register makes a pitch public, without claiming it's free", function () {
    expect(publicUsePitch(['provider' => 'stadt-koeln', 'provider_record_id' => 'reg-1', 'source_url' => 'https://www.offenedaten-koeln.de/dataset/spiel-und-sportplatze-in-koln', 'payload' => ['access' => ['raw' => 'yes']]]))
        ->toBe(['value' => 'yes', 'free' => 'unknown', 'basis' => 'city_register', 'source_url' => 'https://www.offenedaten-koeln.de/dataset/spiel-und-sportplatze-in-koln']);
});

test('fee=no makes a place free and public; a restricted access tag makes it not public', function () {
    expect(publicUsePitch(['provider' => 'osm', 'source_url' => 'https://www.openstreetmap.org/way/1', 'payload' => ['fee' => ['raw' => 'no']]]))
        ->toMatchArray(['value' => 'yes', 'free' => 'yes', 'basis' => 'no_fee'])
        ->and(publicUsePitch(['provider' => 'osm', 'source_url' => 'https://www.openstreetmap.org/way/2', 'payload' => ['access' => ['raw' => 'members'], 'fee' => ['raw' => 'yes']]]))
        ->toMatchArray(['value' => 'no', 'free' => 'no', 'basis' => 'osm_access'])
        ->and(publicUsePitch(['provider' => 'osm', 'source_url' => 'https://www.openstreetmap.org/way/3', 'payload' => ['name' => 'Bolzplatz']]))
        ->toBe(['value' => 'unknown', 'free' => 'unknown', 'basis' => null, 'source_url' => null]);
});

test("a pitch on a school's grounds isn't public unless the register or an access tag says so", function () {
    $school = ['grounds_kind' => 'school', 'grounds_name' => 'Gemeinschaftsgrundschule Europaring', 'grounds_source_id' => 'way/123'];

    expect(publicUsePitch(['provider' => 'osm', 'source_url' => 'https://www.openstreetmap.org/way/4', 'payload' => ['name' => 'Bolzplatz']], $school))
        ->toBe(['value' => 'no', 'free' => 'unknown', 'basis' => 'school_grounds', 'source_url' => 'https://www.openstreetmap.org/way/123'])
        ->and(publicUsePitch(['provider' => 'stadt-koeln', 'provider_record_id' => 'reg-2', 'source_url' => 'https://www.offenedaten-koeln.de/dataset/spiel-und-sportplatze-in-koln', 'payload' => ['access' => ['raw' => 'yes']]], $school))
        ->toMatchArray(['value' => 'yes', 'basis' => 'city_register'])
        ->and(publicUsePitch(['provider' => 'osm', 'source_url' => 'https://www.openstreetmap.org/way/5', 'payload' => ['access' => ['raw' => 'yes', 'conditional' => null]]], $school))
        ->toMatchArray(['value' => 'yes', 'basis' => 'osm_access']);
});

test('school grounds mark only the outdoor facilities inside them', function () {
    DB::table('school_areas')->insert(['osm_id' => 1231, 'source_id' => 'way/123', 'kind' => 'school', 'name' => 'Grundschule Test', 'created_at' => now(), 'updated_at' => now()]);
    DB::statement("UPDATE school_areas SET boundary = ST_GeomFromText('POLYGON((6.949 50.939, 6.951 50.939, 6.951 50.941, 6.949 50.941, 6.949 50.939))', 4326)");
    $inside = Spot::factory()->create(['category' => 'pitch', 'lat' => 50.94, 'lng' => 6.95]);
    $cafe = Spot::factory()->create(['category' => 'cafe', 'lat' => 50.94, 'lng' => 6.95]);
    $outside = Spot::factory()->create(['category' => 'pitch', 'lat' => 50.95, 'lng' => 6.96]);
    foreach ([$inside, $cafe, $outside] as $spot) {
        DB::statement('UPDATE spots SET location = ST_SetSRID(ST_MakePoint(lng, lat), 4326)::geography WHERE id = ?', [$spot->id]);
    }

    $this->artisan('places:import-school-grounds --stamp-only')->assertSuccessful();

    expect($inside->fresh()->grounds_name)->toBe('Grundschule Test')
        ->and($cafe->fresh()->grounds_kind)->toBeNull()
        ->and($outside->fresh()->grounds_kind)->toBeNull();
});
