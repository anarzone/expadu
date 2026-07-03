<?php

use App\Transit\Dto\GeoPoint;
use App\Transit\TransitousAdapter;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
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

    // Walk + bike are fetched as SEPARATE direct calls so both surface — a
    // single combined call only ever returns the fastest (bike).
    Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'directModes=BIKE'));
    Http::assertSent(fn ($req) => str_contains(urldecode($req->url()), 'directModes=WALK'));
});

test('intermediate stops carry coordinates for GPS matching', function () {
    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response([
            'itineraries' => [[
                'startTime' => '2026-06-19T06:00:00Z',
                'endTime' => '2026-06-19T06:31:00Z',
                'duration' => 1860,
                'transfers' => 0,
                'legs' => [[
                    'mode' => 'TRAM',
                    'from' => ['name' => 'START', 'lat' => 50.95, 'lon' => 6.92],
                    'to' => ['name' => 'END', 'lat' => 50.9413, 'lon' => 6.9583],
                    'startTime' => '2026-06-19T06:05:00Z',
                    'endTime' => '2026-06-19T06:28:00Z',
                    'duration' => 1380,
                    'routeShortName' => '12',
                    'intermediateStops' => [[
                        'name' => 'Köln Ebertplatz',
                        'arrival' => '2026-06-19T06:15:00Z',
                        'lat' => 50.9506,
                        'lon' => 6.9588,
                    ]],
                ]],
            ]],
        ]),
    ]);

    $result = (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.9413, 6.9583));
    $stops = $result->journeys[0]->legs[0]->intermediateStops;

    expect($stops[0]['name'])->toBe('Köln Ebertplatz');
    expect($stops[0]['lat'])->toBe(50.9506);
    expect($stops[0]['lng'])->toBe(6.9588);
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

test('travelMatrix maps one-to-many durations in order, null for unreachable', function () {
    Http::fake([
        'api.transitous.org/api/v1/one-to-many*' => Http::response([
            ['duration' => 147],   // ~3 min
            ['duration' => 962],   // ~17 min
            [],                    // unreachable → null
        ]),
    ]);

    $minutes = (new TransitousAdapter)->travelMatrix(
        new GeoPoint(50.9413, 6.9583),
        [new GeoPoint(50.943, 6.956), new GeoPoint(50.9513, 6.9185), new GeoPoint(51.30, 7.50)],
        'BIKE',
    );

    expect($minutes)->toBe([3, 17, null]);

    Http::assertSent(function ($req) {
        $url = urldecode($req->url());

        return str_contains($url, 'one=50.9413;6.9583')
            && str_contains($url, 'many=50.943;6.956,50.9513;6.9185,51.3;7.5')
            && str_contains($req->url(), 'mode=BIKE');
    });
});

test('travelMatrix returns all-null when the response length mismatches', function () {
    Http::fake([
        'api.transitous.org/api/v1/one-to-many*' => Http::response([['duration' => 147]]),
    ]);

    $minutes = (new TransitousAdapter)->travelMatrix(
        new GeoPoint(50.94, 6.95),
        [new GeoPoint(50.95, 6.92), new GeoPoint(50.96, 6.93)],
    );

    expect($minutes)->toBe([null, null]);
});

test('plan throws on http error so the failover can catch it', function () {
    Http::fake([
        'api.transitous.org/*' => Http::response('upstream broke', 503),
    ]);

    (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.94, 6.96));
})->throws(RequestException::class);

/** A leg factory for the rail-rescue / colour tests. */
function fakeLeg(string $mode, array $extra = []): array
{
    return array_merge([
        'mode' => $mode,
        'from' => ['name' => 'A', 'lat' => 50.95, 'lon' => 6.90],
        'to' => ['name' => 'B', 'lat' => 50.98, 'lon' => 6.95],
        'startTime' => '2026-07-06T07:00:00Z',
        'endTime' => '2026-07-06T07:20:00Z',
        'duration' => 1200,
    ], $extra);
}

function fakeItinerary(array $legs, int $transfers, int $durationSec): array
{
    return [
        'startTime' => '2026-07-06T07:00:00Z',
        'endTime' => '2026-07-06T07:00:00Z',
        'duration' => $durationSec,
        'transfers' => $transfers,
        'legs' => $legs,
    ];
}

test('re-plans without buses to surface a competitive S-Bahn route the primary set pruned', function () {
    // Primary set: a tram+bus route, no rail. The rescue call (transitModes,
    // no bus) answers with the pruned tram→S-Bahn option.
    $busOnly = ['itineraries' => [fakeItinerary(
        [fakeLeg('TRAM', ['routeShortName' => '12']), fakeLeg('BUS', ['routeShortName' => '140'])],
        1, 3540,
    )]];
    $railRescue = ['itineraries' => [fakeItinerary(
        [fakeLeg('TRAM', ['routeShortName' => '12']), fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19'])],
        1, 3600,
    )]];

    Http::fake(fn ($request) => str_contains(urldecode($request->url()), 'transitModes=')
        ? Http::response($railRescue)
        : Http::response($busOnly));

    // A rail station sits on the destination, so the rescue is worth a call.
    Cache::put('motis:rail-stations', [['lat' => 50.98, 'lng' => 6.95]]);

    // ~4.8 km apart — far enough that the rail rescue is worth a call.
    $result = (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.90), new GeoPoint(50.98, 6.95));

    $lines = collect($result->journeys)->flatMap(fn ($j) => $j->lines())->all();
    expect($lines)->toContain('S19');

    // The rescue call dropped buses and widened the access/egress walk.
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'transitModes=')
        && ! str_contains(urldecode($r->url()), 'BUS')
        && str_contains($r->url(), 'maxPostTransitTime'));
});

test('does not re-plan without buses when a simple rail option already exists', function () {
    $withRail = ['itineraries' => [fakeItinerary(
        [fakeLeg('TRAM', ['routeShortName' => '12']), fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19'])],
        1, 3600,
    )]];

    Http::fake(['api.transitous.org/*' => Http::response($withRail)]);

    (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.90), new GeoPoint(50.98, 6.95));

    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'transitModes='));
});

test('does not attempt the rail rescue on short trips', function () {
    Http::fake(['api.transitous.org/*' => Http::response(['itineraries' => [fakeItinerary(
        [fakeLeg('TRAM', ['routeShortName' => '12'])], 0, 900,
    )]])]);

    // ~250 m apart — below the rescue distance floor.
    (new TransitousAdapter)->plan(new GeoPoint(50.9500, 6.9500), new GeoPoint(50.9520, 6.9515));

    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'transitModes='));
});

test('a black feed colour falls back to the branded S-Bahn green', function () {
    Http::fake(['api.transitous.org/*' => Http::response(['itineraries' => [fakeItinerary(
        [
            fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19', 'routeColor' => '000000']),
            fakeLeg('TRAM', ['routeShortName' => '1', 'routeColor' => 'E2001A']),
        ],
        1, 1800,
    )]])]);

    // Short trip + a simple rail option → no rescue; just exercise colour mapping.
    $legs = (new TransitousAdapter)->plan(new GeoPoint(50.9500, 6.9500), new GeoPoint(50.9520, 6.9515))
        ->journeys[0]->legs;

    $rail = collect($legs)->firstWhere('mode', 'rail');
    $tram = collect($legs)->firstWhere('mode', 'tram');

    // 000000 is treated as "unset" → S-Bahn green; a real colour passes through.
    expect($rail->lineColor)->toBe('#008D4B');
    expect($tram->lineColor)->toBe('#E2001A');
});
