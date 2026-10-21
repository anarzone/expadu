<?php

use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\VrsRealtimeService;
use App\Services\VrsTriasService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function makeLiveResult(string $stop = 'Neumarkt', array $departures = [['line' => '12', 'direction' => 'Merkenich', 'departures' => [3, 13]]]): array
{
    return [
        'stop_name' => $stop,
        'source' => 'trias_rt',
        'departures' => $departures,
    ];
}

function makeService(?VrsTriasService $trias = null): GtfsDepartureService
{
    return new GtfsDepartureService(
        app(VrsRealtimeService::class),
        $trias ?? app(VrsTriasService::class),
    );
}

beforeEach(function () {
    Cache::flush();
});

// ── getDeparturesNearby ──

it('returns live data when TRIAS succeeds', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(makeLiveResult());

    $service = makeService($trias);
    $result = $service->getDeparturesNearby(50.94, 6.96);

    expect($result['source'])->toBe('trias_rt');
    expect($result['departures'])->toHaveCount(1);
});

it('stores last good result as fallback on success', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(makeLiveResult());

    $service = makeService($trias);
    $service->getDeparturesNearby(50.94, 6.96);

    $fallbackKey = 'trias_last_good_nearby_50.94_6.96';
    expect(Cache::get($fallbackKey))->not->toBeNull();
    expect(Cache::get($fallbackKey)['source'])->toBe('trias_rt');
});

it('serves last good result when TRIAS fails', function () {
    // First call succeeds — populates fallback
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(makeLiveResult());

    $service = makeService($trias);
    $service->getDeparturesNearby(50.94, 6.96);

    // Second call fails — should use fallback
    $trias2 = Mockery::mock(VrsTriasService::class);
    $trias2->shouldReceive('getDeparturesNearby')->once()->andReturn(null);

    $service2 = makeService($trias2);
    $result = $service2->getDeparturesNearby(50.94, 6.96);

    expect($result['source'])->toBe('trias_rt');
    expect($result['stop_name'])->toBe('Neumarkt');
});

it('falls back to static when no last good result exists', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(null);

    $service = makeService($trias);
    $result = $service->getDeparturesNearby(50.94, 6.96);

    expect($result['source'])->toBeIn(['gtfs_static', 'gtfs_rt', 'unavailable']);
});

it('GPS jitter hits same fallback cache — different coords round to same key', function () {
    // First call succeeds and stores fallback
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->andReturn(makeLiveResult(), null);

    $service = makeService($trias);

    // First call with precise coords — succeeds, stores fallback
    $result1 = $service->getDeparturesNearby(50.9401, 6.9601);
    // Second call with GPS jitter — TRIAS returns null, but fallback key matches
    $result2 = $service->getDeparturesNearby(50.9404, 6.9603);

    expect($result1['source'])->toBe('trias_rt');
    expect($result2['source'])->toBe('trias_rt');
});

it('fallback expires after 3 minutes', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(makeLiveResult());

    $service = makeService($trias);
    $service->getDeparturesNearby(50.94, 6.96);

    // Travel 4 minutes forward — fallback should be expired
    $this->travel(4)->minutes();

    $trias2 = Mockery::mock(VrsTriasService::class);
    $trias2->shouldReceive('getDeparturesNearby')->once()->andReturn(null);

    $service2 = makeService($trias2);
    $result = $service2->getDeparturesNearby(50.94, 6.96);

    // Should NOT be trias_rt since fallback expired
    expect($result['source'])->not->toBe('trias_rt');
});

// ── getDepartures (by name) ──

it('getDepartures returns live data when TRIAS succeeds', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->once()->andReturn(makeLiveResult('Ehrenfeld'));

    $service = makeService($trias);
    $result = $service->getDepartures('Ehrenfeld');

    expect($result['source'])->toBe('trias_rt');
    expect($result['stop_name'])->toBe('Ehrenfeld');
});

it('getDepartures serves fallback when TRIAS fails', function () {
    // First call succeeds
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->once()->andReturn(makeLiveResult('Ehrenfeld'));

    $service = makeService($trias);
    $service->getDepartures('Ehrenfeld');

    // Second call fails — should use fallback
    $trias2 = Mockery::mock(VrsTriasService::class);
    $trias2->shouldReceive('getDepartures')->once()->andReturn(null);

    $service2 = makeService($trias2);
    $result = $service2->getDepartures('Ehrenfeld');

    expect($result['source'])->toBe('trias_rt');
});

it('ages cached TRIAS countdowns before serving a fallback', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->once()->andReturn(makeLiveResult('Ehrenfeld'));
    makeService($trias)->getDepartures('Ehrenfeld');

    $this->travel(2)->minutes();

    $failedTrias = Mockery::mock(VrsTriasService::class);
    $failedTrias->shouldReceive('getDepartures')->once()->andReturn(null);
    $result = makeService($failedTrias)->getDepartures('Ehrenfeld');

    expect($result['departures'][0]['departures'])->toBe([1, 11]);
});

it('getDepartures falls back to static when no fallback exists', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->once()->andReturn(null);

    $service = makeService($trias);
    $result = $service->getDepartures('Ehrenfeld');

    expect($result['source'])->toBeIn(['gtfs_static', 'gtfs_rt', 'unavailable']);
});

// ── Resilience simulation ──

it('survives intermittent TRIAS failures without showing static', function () {
    $callCount = 0;

    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->andReturnUsing(function () use (&$callCount) {
        $callCount++;

        // Simulate 20% failure rate: fail on calls 3 and 7
        return in_array($callCount, [3, 7]) ? null : makeLiveResult();
    });

    $service = makeService($trias);

    $results = [];
    for ($i = 0; $i < 10; $i++) {
        // Clear TRIAS cache to force fresh call each time
        Cache::forget('trias_departures_nearby_50.94_6.96_10');
        $results[] = $service->getDeparturesNearby(50.94, 6.96);
    }

    // All 10 should show live — failures served from fallback
    $sources = array_column($results, 'source');
    expect($sources)->each->toBe('trias_rt');
});

// ── While TRIAS is down: the timetable, fast ──

it('keeps the GTFS-RT feed for a whole board, and reads it afresh after half a minute', function () {
    config(['services.vrs.enabled' => true]);
    Http::fake();
    $feed = fn (int $delay) => ['t1' => ['trip_id' => 't1', 'route_id' => 'r16', 'cancelled' => false, 'observed_at' => null,
        'stops' => ['s1' => ['delay' => $delay, 'skipped' => false]]]];
    Cache::put('vrs_gtfsrt_trip_updates_v2', $feed(120), 30);
    $realtime = app(VrsRealtimeService::class);

    expect($realtime->getDelayForTripAtStop('t1', 's1')['delay'])->toBe(120);
    // The rest of the board's departures read it from memory, not the cache.
    Cache::forget('vrs_gtfsrt_trip_updates_v2');
    foreach (range(1, 40) as $row) {
        expect($realtime->getDelayForTripAtStop('t1', 's1')['delay'])->toBe(120);
    }
    Http::assertNothingSent();

    $this->travel(31)->seconds();
    Cache::put('vrs_gtfsrt_trip_updates_v2', $feed(300), 30);
    expect($realtime->getDelayForTripAtStop('t1', 's1')['delay'])->toBe(300);
});

it('remembers a missing timetable for a minute, not the hour a loaded one keeps', function () {
    $this->travelTo(now()->setDate(2026, 10, 21)->setTime(13, 0));
    config(['services.vrs.enabled' => false]);
    $this->mock(DisruptionService::class)->shouldReceive('getDisruptedLines')->andReturn([]);
    $service = makeService();

    expect($service->getStaticDepartures('Busy Square', 10)['source'])->toBe('unavailable');

    DB::table('gtfs_stops')->insert(['stop_id' => 'busy-1', 'stop_name' => 'Busy Square', 'stop_lat' => 50.941, 'stop_lng' => 6.941, 'location_type' => 0]);
    DB::table('gtfs_routes')->insert(['route_id' => 'r16', 'route_short_name' => '16', 'route_type' => 0]);
    DB::table('gtfs_trips')->insert(['trip_id' => 't1', 'route_id' => 'r16', 'service_id' => 'daily', 'trip_headsign' => 'Bonn']);
    DB::table('gtfs_stop_times')->insert(['trip_id' => 't1', 'stop_id' => 'busy-1', 'arrival_time' => '13:10:00', 'departure_time' => '13:10:00', 'stop_sequence' => 1]);
    $this->travel(61)->seconds();

    expect($service->getStaticDepartures('Busy Square', 11)['departures'][0]['line'])->toBe('16');
});

it('skips TRIAS for half a minute after one failure and five once three in a row say it is down', function () {
    config(['services.vrs.enabled' => true, 'services.vrs.trias_url' => 'https://trias.test/v1/trias']);
    $up = false;
    $requests = 0;
    Http::fake(function () use (&$up, &$requests) {
        $requests++;

        return $up ? Http::response('<Trias/>', 200) : Http::response('', 503);
    });
    $trias = app(VrsTriasService::class);
    $asked = function () use (&$requests): int {
        return $requests;
    };

    $trias->getDepartures('Neumarkt');
    expect($asked())->toBe(1);
    $trias->getDepartures('Neumarkt');
    expect($asked())->toBe(1);

    // A blip: back after 30 s, and two more failures make an outage.
    $this->travel(31)->seconds();
    $trias->getDepartures('Neumarkt');
    $this->travel(31)->seconds();
    $trias->getDepartures('Neumarkt');
    expect($asked())->toBe(3);

    $this->travel(31)->seconds();
    $trias->getDepartures('Neumarkt');
    $this->travel(4)->minutes();
    $trias->getDepartures('Neumarkt');
    expect($asked())->toBe(3);

    // After five minutes it's asked again; an answer starts the count over.
    $this->travel(31)->seconds();
    $up = true;
    $trias->getDepartures('Neumarkt');
    expect($asked())->toBe(4)
        ->and(Cache::get('trias_failures'))->toBeNull()
        ->and(Cache::get('trias_down'))->toBeNull();
});
