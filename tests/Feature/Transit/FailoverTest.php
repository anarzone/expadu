<?php

use App\Services\NearbyStopService;
use App\Services\VrsTriasService;
use App\Transit\CircuitBreaker;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Leg;
use App\Transit\FailoverRouteService;
use App\Transit\MotisAdapter;
use App\Transit\TransitousAdapter;
use App\Transit\TriasAdapter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Cache::flush();
    config(['services.motis.url' => 'http://motis.test']);
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'transit:cb:*'
    );
});

function motisPlanFixture(): array
{
    return json_decode(file_get_contents(base_path('tests/Fixtures/transitous/plan.json')), true);
}

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

test('MOTIS serves as the primary provider and the result is cached', function () {
    Http::fake(['motis.test/api/v1/plan*' => Http::response(motisPlanFixture())]);

    $service = app(RouteService::class);
    $from = new GeoPoint(50.9513, 6.9185);
    $to = new GeoPoint(50.9413, 6.9583);

    $first = $service->plan($from, $to);
    $second = $service->plan($from, $to); // must hit the cache

    expect($first->source)->toBe('motis');
    expect($second->source)->toBe('motis');
    // One uncached plan() = four MOTIS calls: transit, a direct bike + walk
    // bucket, and — because this ~3 km fixture has no rail — one bus-free "rail
    // rescue" re-plan. The second plan() is served entirely from cache, so the
    // total stays at 4.
    Http::assertSentCount(4);
});

test('falls back to Transitous when MOTIS fails', function () {
    Http::fake([
        'motis.test/*' => Http::response('motis down', 503),
        'api.transitous.org/api/v3/plan*' => Http::response(motisPlanFixture()),
    ]);

    $result = app(RouteService::class)->plan(
        new GeoPoint(50.9513, 6.9185),
        new GeoPoint(50.9413, 6.9583),
    );

    expect($result->source)->toBe('transitous');
});

test('falls back to TRIAS after MOTIS and Transitous both fail twice', function () {
    Http::fake([
        'motis.test/*' => Http::response('down', 503),
        'api.transitous.org/*' => Http::response('down', 503),
    ]);
    fakeTriasTrips();

    $service = app(RouteService::class);
    $from = new GeoPoint(50.95, 6.92);

    // Distinct destinations bypass the journey cache each round.
    expect($service->plan($from, new GeoPoint(50.9401, 6.9601))->source)->toBe('trias');
    expect($service->plan($from, new GeoPoint(50.9402, 6.9602))->source)->toBe('trias');

    // Two failures each opened both circuits.
    expect(app(CircuitBreaker::class)->isOpen('motis'))->toBeTrue();
    expect(app(CircuitBreaker::class)->isOpen('transitous'))->toBeTrue();

    $third = $service->plan($from, new GeoPoint(50.94, 6.96));
    expect($third->source)->toBe('trias');
    expect($third->journeys[0]->lines())->toContain('5');
});

test('caps the cascade at the time budget and degrades before the last provider', function () {
    Http::fake([
        'motis.test/*' => Http::response('down', 503),
        'api.transitous.org/*' => Http::response('down', 503),
    ]);

    // TRIAS is last in the chain; once the budget is spent it must never be
    // reached. A degraded board still needs a (mocked) nearest-stop lookup.
    test()->mock(VrsTriasService::class, function ($mock) {
        $mock->shouldNotReceive('planJourney');
    });
    test()->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getDeparturesByType')->andReturn(['kvb' => [], 'db' => [], 'stops_used' => []]);
    });

    // Scripted clock (seconds): the deadline base, then one read per adapter to
    // compute remaining. MOTIS + Transitous each get a turn; by TRIAS only ~1s
    // of the 12s budget is left — under the 2s floor — so the cascade stops.
    $service = new class(app(MotisAdapter::class), app(TransitousAdapter::class), app(TriasAdapter::class), app(CircuitBreaker::class), app(NearbyStopService::class)) extends FailoverRouteService
    {
        /** @var list<float> */
        public array $clock = [0.0, 0.0, 1.0, 11.0];

        private int $tick = 0;

        protected function now(): float
        {
            return $this->clock[$this->tick++] ?? 99.0;
        }
    };

    $result = $service->plan(new GeoPoint(50.95, 6.92), new GeoPoint(50.94, 6.96));

    expect($result->source)->toBe('degraded');
    // Proof the gate fired between Transitous and TRIAS, not earlier: both HTTP
    // providers were genuinely attempted before the budget ran out.
    Http::assertSent(fn ($req) => str_contains($req->url(), 'motis.test'));
    Http::assertSent(fn ($req) => str_contains($req->url(), 'api.transitous.org'));
});

test('degrades to nearest-stop departures when every provider dies', function () {
    Http::fake([
        'motis.test/*' => Http::response('down', 503),
        'api.transitous.org/*' => Http::response('down', 503),
    ]);
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

test('travelMatrix routes via MOTIS and caches a useful result', function () {
    Http::fake([
        'motis.test/api/v1/one-to-many*' => Http::response([['duration' => 300], ['duration' => 600]]),
    ]);

    $service = app(RouteService::class);
    $origin = new GeoPoint(50.94, 6.95);
    $dests = [new GeoPoint(50.95, 6.92), new GeoPoint(50.96, 6.93)];

    expect($service->travelMatrix($origin, $dests, 'BIKE'))->toBe([5, 10]);
    expect($service->travelMatrix($origin, $dests, 'BIKE'))->toBe([5, 10]); // cache hit
    Http::assertSentCount(1);
});

test('travelMatrix falls back to all-null when MOTIS errors', function () {
    Http::fake(['motis.test/*' => Http::response('down', 503)]);

    $minutes = app(RouteService::class)->travelMatrix(
        new GeoPoint(50.94, 6.95),
        [new GeoPoint(50.95, 6.92)],
    );

    expect($minutes)->toBe([null]);
});

test('travelMatrix skips an out-of-area origin without any call', function () {
    Http::fake();

    // Munich — outside the NRW service area.
    $minutes = app(RouteService::class)->travelMatrix(
        new GeoPoint(48.14, 11.58),
        [new GeoPoint(48.15, 11.59)],
    );

    expect($minutes)->toBe([null]);
    Http::assertNothingSent();
});

test('a coordinate outside NRW short-circuits to degraded without calling any provider', function () {
    Http::fake(); // any provider call would be recorded
    test()->mock(NearbyStopService::class, function ($mock) {
        $mock->shouldReceive('getDeparturesByType')->andReturn(['kvb' => [], 'db' => [], 'stops_used' => []]);
    });

    // Munich (48.14, 11.58) is well outside the NRW service area.
    $result = app(RouteService::class)->plan(new GeoPoint(50.95, 6.92), new GeoPoint(48.137, 11.575));

    expect($result->source)->toBe('degraded');
    Http::assertNothingSent();
});

test('MOTIS transit legs carry the ridden stations and a line colour', function () {
    Http::fake(['motis.test/api/v1/plan*' => Http::response(motisPlanFixture())]);

    $result = app(RouteService::class)->plan(
        new GeoPoint(50.9513, 6.9185),
        new GeoPoint(50.9413, 6.9583),
    );

    $transitLeg = collect($result->journeys)
        ->flatMap(fn ($journey) => $journey->legs)
        ->first(fn ($leg) => $leg->isTransit() && $leg->intermediateStops !== null);

    // The fixture's transit legs list their intermediate stops — the adapter
    // must surface name + arrival (station-by-station journey timeline)…
    expect($transitLeg)->not->toBeNull();
    expect($transitLeg->intermediateStops[0])->toHaveKeys(['name', 'arrive_at', 'arrive_time']);
    expect($transitLeg->intermediateStops[0]['name'])->not->toBe('');
    // …and a badge colour (feed routeColor, else the KVB map).
    expect($transitLeg->lineColor)->toStartWith('#');

    // Round-trips the result cache (toArray/fromArray stay symmetric).
    $leg = Leg::fromArray($transitLeg->toArray());
    expect($leg->intermediateStops)->toBe($transitLeg->intermediateStops);
    expect($leg->lineColor)->toBe($transitLeg->lineColor);
});
