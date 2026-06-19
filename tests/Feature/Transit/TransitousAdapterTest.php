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

    // Stops ridden = intermediate stops + the get-off stop (fixture has 1
    // intermediate stop on the transit leg); walk legs have none.
    expect(array_values($transitLegs)[0]->stopsCount)->toBe(2);
    $walkLegs = array_filter($journey->legs, fn ($leg) => ! $leg->isTransit());
    expect(array_values($walkLegs)[0]->stopsCount)->toBeNull();

    // Times are normalized to Berlin local time
    expect($journey->departAt->tzName)->toBe('Europe/Berlin');
});

test('includes direct walk and bike routes alongside transit', function () {
    $leg = fn (string $mode, string $start, string $end, int $dur, array $extra = []) => array_merge([
        'mode' => $mode,
        'from' => ['name' => 'START', 'lat' => 50.95, 'lon' => 6.92],
        'to' => ['name' => 'END', 'lat' => 50.9413, 'lon' => 6.9583],
        'startTime' => $start,
        'endTime' => $end,
        'duration' => $dur,
    ], $extra);

    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response([
            'itineraries' => [[
                'startTime' => '2026-06-19T06:00:00Z',
                'endTime' => '2026-06-19T06:31:00Z',
                'duration' => 1860,
                'transfers' => 1,
                'legs' => [
                    $leg('WALK', '2026-06-19T06:00:00Z', '2026-06-19T06:05:00Z', 300),
                    $leg('TRAM', '2026-06-19T06:05:00Z', '2026-06-19T06:28:00Z', 1380, [
                        'routeShortName' => '12',
                        'intermediateStops' => [['name' => 'a'], ['name' => 'b']],
                        'legGeometry' => ['points' => 'abc'],
                    ]),
                    $leg('WALK', '2026-06-19T06:28:00Z', '2026-06-19T06:31:00Z', 180),
                ],
            ]],
            'direct' => [
                [
                    'startTime' => '2026-06-19T06:00:00Z',
                    'endTime' => '2026-06-19T06:22:00Z',
                    'duration' => 1320,
                    'transfers' => 0,
                    'legs' => [$leg('BIKE', '2026-06-19T06:00:00Z', '2026-06-19T06:22:00Z', 1320, ['legGeometry' => ['points' => 'xyz']])],
                ],
                [
                    'startTime' => '2026-06-19T06:00:00Z',
                    'endTime' => '2026-06-19T07:05:00Z',
                    'duration' => 3900,
                    'transfers' => 0,
                    'legs' => [$leg('WALK', '2026-06-19T06:00:00Z', '2026-06-19T07:05:00Z', 3900)],
                ],
            ],
        ]),
    ]);

    $result = (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.9413, 6.9583));

    // Transit itinerary first, then the direct bike + walk options.
    expect($result->journeys)->toHaveCount(3);
    expect(array_map(fn ($j) => $j->mode(), $result->journeys))->toBe(['transit', 'bike', 'walk']);

    // MOTIS was actually asked for direct walk + bike (not transit-only).
    Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'directModes=WALK,BIKE'));
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
