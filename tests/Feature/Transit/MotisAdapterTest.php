<?php

use App\Transit\Dto\GeoPoint;
use App\Transit\MotisAdapter;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['services.motis.url' => 'http://motis.test']);
});

test('routes through our self-hosted MOTIS on /api/v1/plan and stamps source=motis', function () {
    Http::fake([
        'motis.test/api/v1/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
    ]);

    $result = (new MotisAdapter)->plan(
        new GeoPoint(50.9513, 6.9185),
        new GeoPoint(50.9413, 6.9583),
    );

    expect($result->source)->toBe('motis');
    expect($result->journeys)->toHaveCount(2);
    expect($result->journeys[0]->lines())->toContain('S19');

    // The map (phase 4) needs decoded geometry — confirm legGeometry flows
    // through to Leg.polyline.
    $allLegs = array_merge(...array_map(fn ($j) => $j->legs, $result->journeys));
    $withGeometry = array_filter($allLegs, fn ($leg) => $leg->polyline !== null && $leg->polyline !== '');
    expect($withGeometry)->not->toBeEmpty();

    // It hit our MOTIS (v1), not Transitous (v3).
    Http::assertSent(fn ($request) => str_contains($request->url(), 'motis.test/api/v1/plan'));
});

test('reverse-geocode targets our MOTIS instance', function () {
    Http::fake([
        'motis.test/api/v1/reverse-geocode*' => Http::response([
            ['type' => 'PLACE', 'name' => 'Vierungsturm', 'lat' => 50.9413, 'lon' => 6.9581],
        ]),
    ]);

    $place = (new MotisAdapter)->reverseGeocode(new GeoPoint(50.9413, 6.9583));

    expect($place?->name)->toBe('Vierungsturm');
    Http::assertSent(fn ($request) => str_contains($request->url(), 'motis.test/api/v1/reverse-geocode'));
});

test('plan throws on http error so the failover can catch it', function () {
    Http::fake(['motis.test/*' => Http::response('motis down', 503)]);

    (new MotisAdapter)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.94, 6.96));
})->throws(RequestException::class);

test('asks our MOTIS (plan API v1, which counts rides) for no change with maxTransfers=1, never 0', function () {
    Http::fake(['motis.test/*' => Http::response(['itineraries' => [], 'direct' => []])]);

    (new MotisAdapter)->plan(new GeoPoint(50.9358, 6.9477), new GeoPoint(51.0227, 6.9491));
    (new MotisAdapter)->directRides([['from' => new GeoPoint(50.9358, 6.9477), 'to' => new GeoPoint(50.9405, 6.9400), 'at' => now()->toImmutable()]]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'maxTransfers=1'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'maxTransfers=0'));
});
