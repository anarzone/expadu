<?php

use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\TimetableService;
use App\Services\VrsRealtimeService;
use App\Services\VrsTriasService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(fn () => Cache::flush());

test('GTFS service-day times after midnight preserve the next date', function () {
    $service = new GtfsDepartureService(Mockery::mock(VrsRealtimeService::class), Mockery::mock(VrsTriasService::class));
    $method = new ReflectionMethod($service, 'scheduledTime');
    $day = Carbon::parse('2026-10-05T22:00:00+02:00');
    expect($method->invoke($service, $day, '25:10:00')->toIso8601String())->toBe('2026-10-06T01:10:00+02:00')
        ->and($method->invoke($service, $day, '25:70:00'))->toBeNull();
});

test('last good departures remain stale even immediately after the provider fails', function () {
    $observed = now()->subSeconds(45)->toIso8601String();
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->andReturn([
        'source' => 'trias_rt', 'stop_name' => 'Neumarkt', 'observed_at' => $observed,
        'departures' => [['line' => '1', 'departures' => [5, 15]]],
    ], null);
    $service = new GtfsDepartureService(Mockery::mock(VrsRealtimeService::class), $trias);
    $service->getDepartures('Neumarkt');
    $stale = $service->getDepartures('Neumarkt');

    expect($stale['is_stale'])->toBeTrue()
        ->and($stale['time_basis'])->toBe('stale')
        ->and($stale['observed_at'])->toBe($observed);
});

test('nested board caches preserve observed time and exact service times', function () {
    $observed = now()->subMinutes(2)->startOfSecond();
    $departureAt = $observed->copy()->addMinutes(5)->toIso8601String();
    $kvb = Mockery::mock(KvbApiService::class);
    $kvb->shouldReceive('getStops')->andReturn([
        ['name' => 'Neumarkt', 'area' => 'STRAB', 'lat' => 50.94, 'lng' => 6.94],
    ]);
    $gtfs = Mockery::mock(GtfsDepartureService::class);
    $gtfs->shouldReceive('getDepartures', 'getDeparturesNearby')->andReturn([
        'source' => 'trias_rt', 'observed_at' => $observed->toIso8601String(),
        'countdown_at' => $observed->timestamp,
        'departures' => [[
            'line' => '1', 'direction' => 'Bensberg', 'type' => 'tram', 'departures' => [5],
            'services' => [[
                'id' => 'service-1', 'departure_at' => $departureAt, 'scheduled_at' => $departureAt,
                'expected_at' => null, 'time_basis' => 'scheduled', 'cancelled' => false,
                'boarding_point' => null, 'delay' => 0,
            ]],
        ]],
    ]);
    $gtfs->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    $board = (new TimetableService($kvb, $gtfs))->boards(50.94, 6.94)['tram'];

    expect($board['fetched_at'])->toBe($observed->toIso8601String())
        ->and($board['time_basis'])->toBe('stale')
        ->and($board['is_stale'])->toBeTrue()
        ->and($board['departures'][0]['minutes'])->toBe([3])
        ->and($board['departures'][0]['services'][0]['departure_at'])->toBe($departureAt)
        ->and($board['departures'][0]['services'][0]['boarding_point'])->toBeNull();
});

test('a cancelled service without minutes remains visible on the board', function () {
    $service = new TimetableService(Mockery::mock(KvbApiService::class), Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'mapDepartures');
    $rows = $method->invoke($service, ['departures' => [[
        'line' => '4', 'direction' => 'Mülheim', 'type' => 'tram', 'departures' => [], 'cancelled' => true,
    ]]], null, ['name' => 'Neumarkt'], 120);

    expect($rows)->toHaveCount(1)->and($rows[0]['cancelled'])->toBeTrue();
});

test('the all board keeps separate services with the same line and direction and exposes stale inputs', function () {
    $service = new TimetableService(Mockery::mock(KvbApiService::class), Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'mergeAll');
    $makeBoard = fn ($id, $at, $stale = false) => [
        'stop_name' => 'Neumarkt', 'source' => 'trias_rt', 'fetched_at' => now()->subSeconds($stale ? 120 : 10)->toIso8601String(),
        'is_stale' => $stale, 'time_basis' => $stale ? 'stale' : 'realtime',
        'departures' => [[
            'line' => '1', 'destination' => 'Bensberg', 'type' => 'tram', 'minutes' => [$at],
            'services' => [['id' => $id, 'departure_at' => now()->addMinutes($at)->toIso8601String()]],
        ]],
    ];
    $all = $method->invoke($service, $makeBoard('one', 1), $makeBoard('two', 2, true), null);

    expect(array_column($all['departures'][0]['services'], 'id'))->toBe(['one', 'two'])
        ->and($all['departures'][0]['minutes'])->toBe([1, 2])
        ->and($all['is_stale'])->toBeTrue()
        ->and($all['time_basis'])->toBe('stale');
});

test('date-bearing departure services outside the board window cannot bypass the horizon', function () {
    $service = new TimetableService(Mockery::mock(KvbApiService::class), Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'mapDepartures');
    $rows = $method->invoke($service, ['departures' => [[
        'line' => '4', 'direction' => 'Mülheim', 'type' => 'tram', 'departures' => [3],
        'services' => [
            ['id' => 'soon', 'departure_at' => now()->addMinutes(3)->toIso8601String()],
            ['id' => 'far', 'departure_at' => now()->addHours(3)->toIso8601String()],
            ['id' => 'invalid', 'departure_at' => 'invalid'],
            ['id' => 'relative', 'departure_at' => 'now'],
            ['id' => 'empty', 'departure_at' => ''],
            ['id' => 'invalid-date', 'departure_at' => '2026-02-30T12:00:00+01:00'],
        ],
    ]]], null, ['name' => 'Neumarkt'], 120);
    expect(array_column($rows[0]['services'], 'id'))->toBe(['soon']);
});

test('malformed observation timestamps remain explicitly unknown and stale', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->andReturn([
        'source' => 'trias_rt', 'observed_at' => 'invalid',
        'departures' => [['line' => '1', 'departures' => [5]]],
    ]);
    $service = new GtfsDepartureService(Mockery::mock(VrsRealtimeService::class), $trias);
    $result = $service->getDepartures('Neumarkt');
    expect($result['observed_at'])->toBeNull()->and($result['is_stale'])->toBeTrue();
});

test('a feed without an estimated service time is scheduled rather than live', function () {
    $trias = Mockery::mock(VrsTriasService::class);
    $trias->shouldReceive('getDepartures')->andReturn([
        'source' => 'trias_rt', 'observed_at' => now()->toIso8601String(),
        'departures' => [['line' => '1', 'departures' => [5], 'services' => [
            ['id' => 'scheduled', 'departure_at' => now()->addMinutes(5)->toIso8601String(), 'time_basis' => 'scheduled'],
        ]]],
    ]);
    $service = new GtfsDepartureService(Mockery::mock(VrsRealtimeService::class), $trias);
    expect($service->getDepartures('Neumarkt')['time_basis'])->toBe('scheduled');
});

test('expired exact services cannot reappear through rounded countdown fallback', function () {
    $service = new TimetableService(Mockery::mock(KvbApiService::class), Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'adjustForAge');
    $board = $method->invoke($service, [
        'source' => 'trias_rt', 'fetched_at' => now()->subSeconds(40)->timestamp,
        'departures' => [[
            'line' => '4', 'minutes' => [0], 'cancelled' => false,
            'services' => [['id' => 'left', 'departure_at' => now()->subSeconds(10)->toIso8601String()]],
        ]],
    ]);
    expect($board['departures'])->toBe([]);
});

test('a later snapshot replaces an old service estimate when station boards overlap', function () {
    $service = new TimetableService(Mockery::mock(KvbApiService::class), Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'mergeAll');
    $makeBoard = fn ($age, $delay, $cancelled) => [
        'source' => 'trias_rt', 'fetched_at' => now()->subSeconds($age)->toIso8601String(),
        'departures' => [[
            'line' => '1', 'destination' => 'Bensberg', 'type' => 'tram', 'minutes' => [2 + $delay],
            'services' => [['id' => 'same', 'departure_at' => now()->addMinutes(2 + $delay)->toIso8601String(), 'cancelled' => $cancelled]],
        ]],
    ];
    $merged = $method->invoke($service, $makeBoard(30, 0, false), $makeBoard(5, 2, true), null);
    expect($merged['departures'][0]['services'])->toHaveCount(1)
        ->and($merged['departures'][0]['services'][0]['cancelled'])->toBeTrue()
        ->and($merged['departures'][0]['minutes'])->toBe([]);
});

test('different nearby stop areas are identified instead of being presented as one station', function () {
    $kvb = Mockery::mock(KvbApiService::class);
    $kvb->shouldReceive('getStops')->andReturn([
        ['id' => 1, 'name' => 'Tram Square', 'area' => 'STRAB', 'lat' => 50.94, 'lng' => 6.94],
        ['id' => 2, 'name' => 'Bus Street', 'area' => 'BUS', 'lat' => 50.9402, 'lng' => 6.9402],
    ]);
    $gtfs = Mockery::mock(GtfsDepartureService::class);
    $gtfs->shouldReceive('getDepartures')->andReturn(['source' => 'gtfs_static', 'departures' => []]);
    $gtfs->shouldReceive('getDeparturesNearby')->andReturnUsing(fn ($lat) => [
        'source' => 'gtfs_static', 'departures' => [[
            'line' => '1', 'direction' => 'Destination', 'type' => $lat === 50.94 ? 'tram' : 'bus', 'departures' => [5],
        ]],
    ]);
    $gtfs->shouldReceive('routeContext')->andReturn(['direction' => null, 'via' => []]);
    $board = (new TimetableService($kvb, $gtfs))->boards(50.94, 6.94)['all'];
    expect($board['scope'])->toBe('nearby')
        ->and(array_column($board['stops'], 'name'))->toBe(['Tram Square', 'Bus Street'])
        ->and($board['stop']['coordinate_kind'])->toBe('stop_area')
        ->and($board['stop']['boarding_confirmed'])->toBeFalse()
        ->and(array_column($board['departures'], 'stop_name'))->toBe(['Tram Square', 'Bus Street']);
});
