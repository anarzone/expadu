<?php

use App\Services\GtfsDepartureService;
use App\Services\VrsRealtimeService;
use App\Services\VrsTriasService;
use Illuminate\Support\Facades\Cache;

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

it('fallback expires after 5 minutes', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDeparturesNearby')->once()->andReturn(makeLiveResult());

    $service = makeService($trias);
    $service->getDeparturesNearby(50.94, 6.96);

    // Travel 6 minutes forward — fallback should be expired
    $this->travel(6)->minutes();

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
