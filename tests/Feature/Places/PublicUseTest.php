<?php

use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;

function publicUsePitch(array $observation): array
{
    $spot = Spot::factory()->create(['category' => 'pitch', 'source' => 'osm', 'source_id' => 'way/'.random_int(1_000_000, 9_999_999), 'tags' => [], 'lat' => 50.94, 'lng' => 6.95]);
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
