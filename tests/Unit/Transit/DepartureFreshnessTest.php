<?php

use App\Services\GtfsDepartureService;
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
    $gtfs = Mockery::mock(GtfsDepartureService::class);
    $gtfs->shouldReceive('getDeparturesNearby')->andReturn([
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
    $board = (new TimetableService($gtfs))->pointBoard(['name' => 'Neumarkt', 'lat' => 50.94, 'lng' => 6.94]);

    expect($board['fetched_at'])->toBe($observed->toIso8601String())
        ->and($board['time_basis'])->toBe('stale')
        ->and($board['is_stale'])->toBeTrue()
        ->and($board['departures'][0]['minutes'])->toBe([3])
        ->and($board['departures'][0]['services'][0]['departure_at'])->toBe($departureAt)
        ->and($board['departures'][0]['services'][0]['boarding_point'])->toBeNull();
});

test('a cancelled service without minutes remains visible on the board', function () {
    $service = new TimetableService(Mockery::mock(GtfsDepartureService::class));
    $method = new ReflectionMethod($service, 'mapDepartures');
    $rows = $method->invoke($service, ['departures' => [[
        'line' => '4', 'direction' => 'Mülheim', 'type' => 'tram', 'departures' => [], 'cancelled' => true,
    ]]], ['name' => 'Neumarkt'], 120);

    expect($rows)->toHaveCount(1)->and($rows[0]['cancelled'])->toBeTrue();
});

test('date-bearing departure services outside the board window cannot bypass the horizon', function () {
    $service = new TimetableService(Mockery::mock(GtfsDepartureService::class));
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
    ]]], ['name' => 'Neumarkt'], 120);
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
    $service = new TimetableService(Mockery::mock(GtfsDepartureService::class));
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
