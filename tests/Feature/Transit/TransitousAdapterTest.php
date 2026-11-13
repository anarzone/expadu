<?php

use App\Services\GtfsRailStations;
use App\Transit\Dto\GeoPoint;
use App\Transit\Polyline;
use App\Transit\TransitousAdapter;
use Carbon\CarbonImmutable;
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

    config(['services.motis.bike_routes' => true]);

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

test('an arrive-by search keeps the latest arrivals, not the earliest', function () {
    // A UTC tram leg arriving at $arrZ (44 min ride), the shape MOTIS returns.
    $itin = function (string $arrZ) {
        $depZ = CarbonImmutable::parse($arrZ)->subMinutes(44)->toIso8601ZuluString();

        return [
            'startTime' => $depZ,
            'endTime' => $arrZ,
            'duration' => 2640,
            'transfers' => 0,
            'legs' => [[
                'mode' => 'TRAM',
                'routeShortName' => '12',
                'from' => ['name' => 'A', 'lat' => 50.95, 'lon' => 6.90],
                'to' => ['name' => 'B', 'lat' => 50.98, 'lon' => 6.95],
                'startTime' => $depZ,
                'endTime' => $arrZ,
                'duration' => 2640,
            ]],
        ];
    };

    // Target: arrive by 20:30 local (18:30Z). MOTIS returns a backward spread of
    // arrivals; only those close to the target should survive the prune.
    Http::fake(['api.transitous.org/*' => Http::response(['itineraries' => [
        $itin('2026-07-06T15:00:00Z'), // 17:00 local — hours early
        $itin('2026-07-06T16:00:00Z'), // 18:00 local — too early
        $itin('2026-07-06T17:00:00Z'), // 19:00 local
        $itin('2026-07-06T17:45:00Z'), // 19:45 local
        $itin('2026-07-06T18:10:00Z'), // 20:10 local
        $itin('2026-07-06T18:25:00Z'), // 20:25 local — closest to target
    ]])]);

    $result = (new TransitousAdapter)->plan(
        new GeoPoint(50.95, 6.90),
        new GeoPoint(50.98, 6.95),
        CarbonImmutable::parse('2026-07-06T18:30:00Z'),
        10,
        true, // arriveBy
    );

    $arrivals = collect($result->journeys)
        ->filter(fn ($j) => $j->mode() === 'transit')
        ->map(fn ($j) => $j->arriveAt->setTimezone('Europe/Berlin')->format('H:i'))
        ->all();

    expect($arrivals)->toContain('20:25');       // the option closest to the target
    expect($arrivals)->not->toContain('17:00');  // the far-too-early one is pruned
    expect($arrivals)->not->toContain('18:00');
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

test('adds the routes a less-walking or no-change ask finds, once each, beside the main answer', function () {
    // Main answer: tram 12 then a long walk. Less walking: 12 then 3 to the door (new), and 12
    // again (known, skipped). No change: 12 only (known, skipped).
    $direct = fakeItinerary([fakeLeg('TRAM', ['routeShortName' => '12'])], 0, 2580);
    $change = fn (string $at) => array_merge(fakeItinerary([
        fakeLeg('TRAM', ['routeShortName' => '12', 'startTime' => $at]),
        fakeLeg('TRAM', ['routeShortName' => '3']),
    ], 1, 2700), ['startTime' => $at]);

    Http::fake(function ($request) use ($direct, $change) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, 'directModes=') => Http::response(['direct' => []]),
            str_contains($url, 'maxPostTransitTime=300') => Http::response(['itineraries' => [
                $change('2026-07-06T07:00:00Z'), $change('2026-07-06T07:10:00Z'), $change('2026-07-06T07:20:00Z'), $direct,
                // The 12 to its end and back a stop, for a shorter walk: never a way.
                fakeItinerary([fakeLeg('TRAM', ['routeShortName' => '12']), fakeLeg('TRAM', ['routeShortName' => '12'])], 1, 2900),
            ]]),
            default => Http::response(['itineraries' => [$direct]]),
        };
    });

    $result = (new TransitousAdapter)->plan(new GeoPoint(51.02, 6.95), new GeoPoint(50.94, 6.95));
    $routes = collect($result->journeys)->map(fn ($j) => implode('>', $j->lines()))->countBy()->all();

    // The main route once; the new one with its next two runs, not three.
    expect($routes)->toBe(['12' => 1, '12>3' => 2]);
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'maxTransfers=0'));
});

test('adds other ways on from the best way\'s first tram, asked via the stops it passes nearest the destination', function () {
    $stop = fn (string $name, string $id, float $lat) => ['name' => $name, 'stopId' => $id, 'lat' => $lat, 'lon' => 6.95];
    $ride = fn (string $line, string $trip, string $from, string $to, string $end) => fakeLeg('TRAM', [
        'routeShortName' => $line, 'tripId' => $trip, 'from' => $stop($from, "id-{$from}", 51.02), 'to' => $stop($to, "id-{$to}", 50.95), 'endTime' => $end,
    ]);
    $way = fn (array $legs, string $end) => array_merge(fakeItinerary($legs, count($legs) - 1, 2400), ['endTime' => $end]);
    // Best: the 12 to Ebertplatz, then the 16, there at 07:40.
    $best = $way([$ride('12', 'run-12', 'Merkenich', 'Ebertplatz', '2026-07-06T07:25:00Z'), $ride('16', 'run-16', 'Ebertplatz', 'Neumarkt', '2026-07-06T07:40:00Z')], '2026-07-06T07:40:00Z');

    Http::fake(function ($request) use ($way, $ride, $best, $stop) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, '/api/v1/trip') => Http::response(['legs' => [[
                'from' => $stop('Merkenich', 'id-Merkenich', 51.02),
                'intermediateStops' => [$stop('Ebertplatz', 'id-Ebertplatz', 50.95), $stop('Hansaring', 'id-Hansaring', 50.948), $stop('Friesenplatz', 'id-Friesenplatz', 50.94)],
                'to' => $stop('Zollstock', 'id-Zollstock', 50.90),
            ]]]),
            // Via Friesenplatz: the same 12 then the 5, five minutes later; and a way that gets there too late.
            str_contains($url, 'via=id-Friesenplatz') => Http::response(['itineraries' => [
                $way([$ride('12', 'run-12', 'Merkenich', 'Friesenplatz', '2026-07-06T07:30:00Z'), $ride('5', 'run-5', 'Friesenplatz', 'Neumarkt', '2026-07-06T07:45:00Z')], '2026-07-06T07:45:00Z'),
                $way([$ride('12', 'run-12', 'Merkenich', 'Friesenplatz', '2026-07-06T07:30:00Z'), $ride('1', 'run-1', 'Friesenplatz', 'Neumarkt', '2026-07-06T08:10:00Z')], '2026-07-06T08:10:00Z'),
            ]]),
            // Via Hansaring: only a later 12 then the S19, not the same first tram.
            str_contains($url, 'via=id-Hansaring') => Http::response(['itineraries' => [
                $way([$ride('12', 'run-12-later', 'Merkenich', 'Hansaring', '2026-07-06T07:48:00Z'), $ride('S19', 'run-s19', 'Hansaring', 'Neumarkt', '2026-07-06T07:55:00Z')], '2026-07-06T07:55:00Z'),
            ]]),
            str_contains($url, 'directModes=') => Http::response(['direct' => []]),
            default => Http::response(['itineraries' => [$best]]),
        };
    });

    $result = (new TransitousAdapter)->plan(new GeoPoint(51.02, 6.95), new GeoPoint(50.94, 6.95));
    $routes = collect($result->journeys)->map(fn ($j) => implode('>', $j->lines()))->all();

    expect($routes)->toContain('12>16', '12>5')
        ->not->toContain('12>1')
        ->not->toContain('12>S19');
    // Not via Ebertplatz, where the best way already changes.
    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'via=id-Ebertplatz'));
});

test('a way with a ride the feed cannot name is left out, and an S-Bahn ride is a train', function () {
    $unnamed = fakeItinerary([fakeLeg('WALK'), fakeLeg('OTHER', ['routeShortName' => '?']), fakeLeg('TRAM', ['routeShortName' => '12'])], 1, 2580);
    $sbahn = fakeItinerary([fakeLeg('SUBURBAN', ['routeShortName' => 'S6'])], 0, 1800);

    Http::fake(fn ($request) => str_contains(urldecode($request->url()), 'directModes=')
        ? Http::response(['direct' => []])
        : Http::response(['itineraries' => [$unnamed, $sbahn]]));

    $journeys = (new TransitousAdapter)->plan(new GeoPoint(50.93, 6.94), new GeoPoint(51.02, 6.95))->journeys;

    expect($journeys)->toHaveCount(1)
        ->and($journeys[0]->legs[0]->mode)->toBe('rail')
        ->and($journeys[0]->legs[0]->lineName)->toBe('S6');
});

test('adds the ways onto the main line at the interchanges near the start, not a long walk or a second change', function () {
    $stop = fn (string $name, float $lat) => ['name' => $name, 'stopId' => "id-{$name}", 'lat' => $lat, 'lon' => 6.95];
    $ride = fn (string $line, string $from, string $to, string $end) => fakeLeg('TRAM', [
        'routeShortName' => $line, 'tripId' => "run-{$line}", 'from' => $stop($from, 50.94), 'to' => $stop($to, 51.02), 'endTime' => $end,
    ]);
    $walk = fn (int $minutes) => fakeLeg('WALK', ['duration' => $minutes * 60]);
    $way = fn (array $legs, string $end) => array_merge(fakeItinerary($legs, count(array_filter($legs, fn ($l) => $l['mode'] !== 'WALK')) - 1, 2700), ['endTime' => $end]);
    // Best: eleven minutes on foot to Rudolfplatz, then the 12 home.
    $best = $way([$walk(11), $ride('12', 'Rudolfplatz', 'Merkenich Mitte', '2026-07-06T07:40:00Z')], '2026-07-06T07:46:00Z');

    Http::fake(function ($request) use ($way, $ride, $walk, $best, $stop) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, '/api/v1/trip') => Http::response(['legs' => [[
                'from' => $stop('Rudolfplatz', 50.936),
                'intermediateStops' => [$stop('Christophstr.', 50.944), $stop('Ebertplatz', 50.951)],
                'to' => $stop('Merkenich Mitte', 51.024),
            ]]]),
            // Via Ebertplatz, changing there: the 18 from the start, then the same 12.
            str_contains($url, 'via=id-Ebertplatz') => Http::response(['itineraries' => [
                $way([$walk(3), $ride('18', 'Neumarkt', 'Ebertplatz', '2026-07-06T07:15:00Z'), $ride('12', 'Ebertplatz', 'Merkenich Mitte', '2026-07-06T07:40:00Z')], '2026-07-06T07:46:00Z'),
            ]]),
            // Via Christophstr.: the long walk again, a 15 one stop, then the 12 it could have taken.
            str_contains($url, 'via=id-Christophstr.') => Http::response(['itineraries' => [
                $way([$walk(11), $ride('15', 'Rudolfplatz', 'Christophstr.', '2026-07-06T07:05:00Z'), $ride('12', 'Christophstr.', 'Merkenich Mitte', '2026-07-06T07:40:00Z')], '2026-07-06T07:46:00Z'),
            ]]),
            str_contains($url, 'directModes=') => Http::response(['direct' => []]),
            default => Http::response(['itineraries' => [$best]]),
        };
    });

    $routes = collect((new TransitousAdapter)->plan(new GeoPoint(50.936, 6.947), new GeoPoint(51.023, 6.949))->journeys)
        ->map(fn ($j) => implode('>', $j->lines()))->all();

    expect($routes)->toContain('12', '18>12')->not->toContain('15>12');
    // Each asked as a change there (a minute's stay), not riding through.
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'via=id-Ebertplatz') && str_contains($r->url(), 'viaMinimumStay=1'));
});

test('adds the ways that get off at another stop near the destination, no more rides than the best', function () {
    $stop = fn (string $name, float $lat) => ['name' => $name, 'stopId' => "id-{$name}", 'lat' => $lat, 'lon' => 6.975];
    $ride = fn (string $line, string $to, string $end) => fakeLeg('TRAM', [
        'routeShortName' => $line, 'tripId' => "run-{$line}", 'from' => $stop('Neumarkt', 50.9357), 'to' => $stop($to, 50.935), 'endTime' => $end,
    ]);
    $way = fn (array $legs, string $end) => array_merge(fakeItinerary($legs, count(array_filter($legs, fn ($l) => $l['mode'] !== 'WALK')) - 1, 720), ['endTime' => $end]);
    $best = $way([$ride('3', 'Suevenstr.', '2026-07-06T07:10:00Z')], '2026-07-06T07:12:00Z');

    Http::fake(function ($request) use ($way, $ride, $best, $stop) {
        $url = urldecode($request->url());

        return match (true) {
            str_contains($url, '/api/v1/map/stops') => Http::response([$stop('Deutzer Freiheit', 50.9367), $stop('Suevenstr.', 50.9330), $stop('Bf Deutz/Messe', 50.9396)]),
            str_contains($url, '/api/v1/trip') => Http::response(['legs' => []]),
            // Off at Deutzer Freiheit: the 7, three minutes later. Off at Bf Deutz/Messe: only with a change.
            str_contains($url, 'via=id-Deutzer Freiheit') => Http::response(['itineraries' => [$way([$ride('7', 'Deutzer Freiheit', '2026-07-06T07:11:00Z')], '2026-07-06T07:15:00Z')]]),
            str_contains($url, 'via=id-Bf Deutz/Messe') => Http::response(['itineraries' => [$way([$ride('1', 'Heumarkt', '2026-07-06T07:05:00Z'), $ride('9', 'Bf Deutz/Messe', '2026-07-06T07:12:00Z')], '2026-07-06T07:16:00Z')]]),
            str_contains($url, 'directModes=') => Http::response(['direct' => []]),
            default => Http::response(['itineraries' => [$best]]),
        };
    });

    $routes = collect((new TransitousAdapter)->plan(new GeoPoint(50.9357, 6.9477), new GeoPoint(50.9358, 6.9752))->journeys)
        ->map(fn ($j) => implode('>', $j->lines()))->all();

    expect($routes)->toContain('3', '7')->not->toContain('1>9');
    // Not asked to get off where the best way already does.
    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'via=id-Suevenstr.'));
});

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
    Cache::put(GtfsRailStations::CACHE_KEY, [['name' => 'Köln Geldernstr./Parkgürtel', 'lat' => 50.98, 'lng' => 6.95]]);

    // ~4.8 km apart — far enough that the rail rescue is worth a call.
    $result = (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.90), new GeoPoint(50.98, 6.95));

    $lines = collect($result->journeys)->flatMap(fn ($j) => $j->lines())->all();
    expect($lines)->toContain('S19');

    // The rescue call dropped buses and widened the access/egress walk.
    Http::assertSent(fn ($r) => str_contains(urldecode($r->url()), 'transitModes=')
        && ! str_contains(urldecode($r->url()), 'BUS')
        && str_contains($r->url(), 'maxPostTransitTime'));
});

test('does not re-plan when a clean bus-free rail option already exists', function () {
    // Tram 12 -> S19 (no bus) is already a clean rail route, so no rescue.
    $cleanRail = ['itineraries' => [fakeItinerary(
        [fakeLeg('TRAM', ['routeShortName' => '12']), fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19'])],
        1, 3600,
    )]];

    Http::fake(['api.transitous.org/*' => Http::response($cleanRail)]);
    Cache::put(GtfsRailStations::CACHE_KEY, [['name' => 'Köln Geldernstr./Parkgürtel', 'lat' => 50.98, 'lng' => 6.95]]);

    (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.90), new GeoPoint(50.98, 6.95));

    Http::assertNotSent(fn ($r) => str_contains(urldecode($r->url()), 'transitModes='));
});

test('re-plans when the only rail option relies on a bus (recovers rail + walk)', function () {
    // Primary offers only S19 -> bus (a bus egress); the rescue answers with the
    // cleaner rail-only route MOTIS's default egress-walk cap had hidden.
    $busTailed = ['itineraries' => [fakeItinerary(
        [fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19']), fakeLeg('BUS', ['routeShortName' => '141'])],
        1, 2400,
    )]];
    $railOnly = ['itineraries' => [fakeItinerary(
        [fakeLeg('REGIONAL_RAIL', ['routeShortName' => 'S19'])],
        0, 1560,
    )]];

    Http::fake(fn ($request) => str_contains(urldecode($request->url()), 'transitModes=')
        ? Http::response($railOnly)
        : Http::response($busTailed));

    Cache::put(GtfsRailStations::CACHE_KEY, [['name' => 'Köln Geldernstr./Parkgürtel', 'lat' => 50.98, 'lng' => 6.95]]);

    $result = (new TransitousAdapter)->plan(new GeoPoint(50.95, 6.90), new GeoPoint(50.98, 6.95));

    // A bus-free rail journey (rail + walk only) is now among the options.
    $cleanRail = collect($result->journeys)->first(fn ($j) => collect($j->legs)->contains(fn ($l) => $l->mode === 'rail')
        && collect($j->legs)->doesntContain(fn ($l) => $l->mode === 'bus'));

    expect($cleanRail)->not->toBeNull();
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

test('a leg\'s line comes at one precision (7) whatever MOTIS sends, so maps draw it where it is', function () {
    $neumarktToStadtgarten = [[50.93577, 6.947677], [50.9365, 6.933054], [50.9405, 6.9343]];
    Http::fake(['api.transitous.org/api/v3/plan*' => Http::response(['itineraries' => [fakeItinerary([
        fakeLeg('TRAM', ['routeShortName' => '1', 'legGeometry' => ['points' => Polyline::encode($neumarktToStadtgarten, 6), 'precision' => 6, 'length' => 3]]),
        fakeLeg('WALK', ['legGeometry' => ['points' => Polyline::encode($neumarktToStadtgarten, 7), 'precision' => 7]]),
    ], 0, 1200)]])]);

    $legs = (new TransitousAdapter)->plan(new GeoPoint(50.93577, 6.947677), new GeoPoint(50.9405, 6.9343))->journeys[0]->legs;

    foreach ($legs as $leg) {
        expect(Polyline::decode($leg->polyline, 7))->toEqualWithDelta($neumarktToStadtgarten, 0.000001);
    }
});
