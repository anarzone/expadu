<?php

use App\Services\NearbyStopService;
use App\Services\VrsTriasService;
use App\Transit\CircuitBreaker;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Cache::flush();
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'transit:cb:*'
    );
});

function fakeTriasTrips(): void
{
    test()->mock(VrsTriasService::class, function ($mock) {
        $mock->shouldReceive('planJourney')->andReturn([
            'source' => 'trias',
            'trips' => [[
                'total_duration_min' => 23,
                'departure_time' => '14:00',
                'arrival_time' => '14:23',
                'transfers' => 0,
                'segments' => [[
                    'type' => 'transit',
                    'line' => '5',
                    'mode' => 'tram',
                    'direction' => 'Butzweilerhof',
                    'boarding_stop' => 'Friesenplatz',
                    'alighting_stop' => 'Dom/Hbf',
                    'boarding_stop_id' => 'S1',
                    'alighting_stop_id' => 'S2',
                    'departure_time' => '14:02',
                    'arrival_time' => '14:20',
                    'duration_min' => 18,
                    'coordinates' => [[6.94, 50.94], [6.958, 50.941]],
                ]],
                'steps' => [],
            ]],
        ]);
    });
}

test('falls back to TRIAS after two transitous failures', function () {
    Http::fake(['api.transitous.org/*' => Http::response('down', 503)]);
    fakeTriasTrips();

    $service = app(RouteService::class);
    $from = new GeoPoint(50.95, 6.92);
    $to = new GeoPoint(50.94, 6.96);

    // Failure 1: transitous fails, TRIAS serves (cache bypassed via distinct coords)
    $first = $service->plan($from, new GeoPoint(50.9401, 6.9601));
    expect($first->source)->toBe('trias');

    // Failure 2 opens the circuit
    $second = $service->plan($from, new GeoPoint(50.9402, 6.9602));
    expect($second->source)->toBe('trias');

    expect(app(CircuitBreaker::class)->isOpen('transitous'))->toBeTrue();

    // Circuit open: transitous is skipped entirely (no further HTTP calls)
    $third = $service->plan($from, $to);
    expect($third->source)->toBe('trias');
    expect($third->journeys[0]->lines())->toContain('5');
});

test('degrades to nearest-stop departures when both providers die', function () {
    Http::fake(['api.transitous.org/*' => Http::response('down', 503)]);
    test()->mock(VrsTriasService::class, function ($mock) {
        $mock->shouldReceive('planJourney')->andReturn(null);
    });
    test()->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getDeparturesByType')->andReturn([
            'kvb' => [
                ['line' => '5', 'direction' => 'Butzweilerhof', 'departures' => [3, 13], 'stop_name' => 'Friesenplatz', 'walk_min' => 4],
            ],
            'db' => [],
            'stops_used' => ['Friesenplatz'],
        ]);
    });

    $result = app(RouteService::class)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.94, 6.96));

    expect($result->source)->toBe('degraded');
    expect($result->journeys)->toBeEmpty();
    expect($result->degraded['departures'])->not->toBeEmpty();
    expect($result->degraded['nearest_stop'])->toBe(['name' => 'Friesenplatz', 'walk_min' => 4]);
    expect($result->degraded['deep_links']['google'])->toContain('travelmode=transit');
});

test('successful transitous response closes the loop and caches', function () {
    Http::fake([
        'api.transitous.org/api/v3/plan*' => Http::response(
            json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true),
        ),
    ]);

    $service = app(RouteService::class);
    $from = new GeoPoint(50.9513, 6.9185);
    $to = new GeoPoint(50.9413, 6.9583);

    $first = $service->plan($from, $to);
    $second = $service->plan($from, $to); // second call must hit the cache

    expect($first->source)->toBe('transitous');
    expect($second->source)->toBe('transitous');
    Http::assertSentCount(1);
});
