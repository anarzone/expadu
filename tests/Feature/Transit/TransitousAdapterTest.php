<?php

use App\Transit\Dto\GeoPoint;
use App\Transit\TransitousAdapter;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

test('maps a real MOTIS plan response to journeys', function () {
    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
    ]);

    $result = (new TransitousAdapter)->plan(
        new GeoPoint(50.9513, 6.9185),
        new GeoPoint(50.9413, 6.9583),
    );

    expect($result->source)->toBe('transitous');
    expect($result->journeys)->toHaveCount(2);

    $journey = $result->journeys[0];
    expect($journey->durationMin)->toBeGreaterThan(0);
    expect($journey->legs)->not->toBeEmpty();

    $transitLegs = array_filter($journey->legs, fn ($leg) => $leg->isTransit());
    expect($transitLegs)->not->toBeEmpty();
    expect($journey->lines())->toContain('S19');

    // Times are normalized to Berlin local time
    expect($journey->departAt->tzName)->toBe('Europe/Berlin');
});

test('geocode maps stops with ids', function () {
    Http::fake([
        'api.transitous.org/api/v1/geocode*' => Http::response([
            [
                'type' => 'STOP',
                'name' => 'Köln Ehrenfeld Bf',
                'id' => 'de-DELFI_de:05315:14000',
                'lat' => 50.9519,
                'lon' => 6.9185,
            ],
            [
                'type' => 'ADDRESS',
                'name' => 'Ehrenfeldgürtel 1',
                'lat' => 50.95,
                'lon' => 6.92,
            ],
        ]),
    ]);

    $places = (new TransitousAdapter)->geocode('Ehrenfeld');

    expect($places)->toHaveCount(2);
    expect($places[0]->stopId)->toBe('de-DELFI_de:05315:14000');
    expect($places[1]->stopId)->toBeNull();
});

test('plan throws on http error so the failover can catch it', function () {
    Http::fake([
        'api.transitous.org/*' => Http::response('upstream broke', 503),
    ]);

    (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.94, 6.96));
})->throws(RequestException::class);
