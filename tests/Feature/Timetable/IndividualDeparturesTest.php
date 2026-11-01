<?php

use App\Services\DisruptionService;
use App\Services\GtfsDepartureService;
use App\Services\VrsRealtimeService;
use Illuminate\Support\Facades\DB;

test('GTFS departures carry exact dated identities and the corresponding boarding stop', function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(23, 0));
    config(['services.vrs.enabled' => false]);
    $this->mock(DisruptionService::class)->shouldReceive('getDisruptedLines')->andReturn([]);
    DB::table('gtfs_stops')->insert([
        ['stop_id' => 'Z-stop', 'stop_name' => 'Test Station', 'stop_lat' => 50.94, 'stop_lng' => 6.94, 'location_type' => 0],
        ['stop_id' => 'A-stop', 'stop_name' => 'Test Station', 'stop_lat' => 50.9401, 'stop_lng' => 6.9401, 'location_type' => 0],
    ]);
    DB::table('gtfs_routes')->insert(['route_id' => 'test-route', 'route_short_name' => '1', 'route_type' => 0]);
    DB::table('gtfs_trips')->insert(['trip_id' => 'test-trip', 'route_id' => 'test-route', 'service_id' => 'test-service', 'trip_headsign' => 'Destination']);
    DB::table('gtfs_stop_times')->insert([
        ['trip_id' => 'test-trip', 'stop_id' => 'Z-stop', 'arrival_time' => '25:10:00', 'departure_time' => '25:10:00', 'stop_sequence' => 1],
        ['trip_id' => 'test-trip', 'stop_id' => 'A-stop', 'arrival_time' => '25:12:00', 'departure_time' => '25:12:00', 'stop_sequence' => 2],
    ]);

    $result = app(GtfsDepartureService::class)->getStaticDepartures('Test Station');
    $departure = $result['departures'][0]['services'][0];
    expect($departure['scheduled_at'])->toBe('2026-10-06T01:10:00+02:00')
        ->and($departure['boarding_point']['id'])->toBe('Z-stop')
        ->and($departure['expected_at'])->toBeNull()
        ->and($departure['time_basis'])->toBe('scheduled')
        ->and($departure['id'])->not->toBeEmpty()
        ->and($result['time_basis'])->toBe('scheduled');
});

test('individual GTFS estimates retain delay seconds instead of rounded minutes', function () {
    $this->travelTo(now()->setDate(2026, 10, 5)->setTime(12, 0));
    config(['services.vrs.enabled' => true]);
    $this->mock(DisruptionService::class)->shouldReceive('getDisruptedLines')->andReturn([]);
    $this->mock(VrsRealtimeService::class, function ($mock) {
        $mock->shouldReceive('getTripUpdates')->andReturn(['delay-trip' => ['trip_id' => 'delay-trip']]);
        $mock->shouldReceive('getDelayForTripAtStop')->with('delay-trip', 'delay-stop')->andReturn([
            'delay' => 70, 'skipped' => false, 'cancelled' => false, 'observed_at' => now()->toIso8601String(),
        ]);
    });
    DB::table('gtfs_stops')->insert(['stop_id' => 'delay-stop', 'stop_name' => 'Delay Stop', 'stop_lat' => 50.94, 'stop_lng' => 6.94, 'location_type' => 0]);
    DB::table('gtfs_routes')->insert(['route_id' => 'delay-route', 'route_short_name' => '1', 'route_type' => 0]);
    DB::table('gtfs_trips')->insert(['trip_id' => 'delay-trip', 'route_id' => 'delay-route', 'service_id' => 'delay-service', 'trip_headsign' => 'Destination']);
    DB::table('gtfs_stop_times')->insert(['trip_id' => 'delay-trip', 'stop_id' => 'delay-stop', 'arrival_time' => '12:05:00', 'departure_time' => '12:05:00', 'stop_sequence' => 1]);

    $result = app(GtfsDepartureService::class)->getStaticDepartures('Delay Stop');
    expect($result['departures'][0]['services'][0]['expected_at'])->toBe('2026-10-05T12:06:10+02:00');
});

test('after midnight GTFS retains the previous service days overnight departure', function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(0, 30));
    config(['services.vrs.enabled' => false]);
    $this->mock(DisruptionService::class)->shouldReceive('getDisruptedLines')->andReturn([]);
    DB::table('gtfs_stops')->insert(['stop_id' => 'night-stop', 'stop_name' => 'Night Stop', 'stop_lat' => 50.94, 'stop_lng' => 6.94, 'location_type' => 0]);
    DB::table('gtfs_routes')->insert(['route_id' => 'night-route', 'route_short_name' => '1', 'route_type' => 0]);
    DB::table('gtfs_trips')->insert(['trip_id' => 'night-trip', 'route_id' => 'night-route', 'service_id' => 'night-service', 'trip_headsign' => 'Destination']);
    DB::table('gtfs_calendar')->insert(['service_id' => 'night-service', 'monday' => true, 'tuesday' => false, 'wednesday' => false, 'thursday' => false, 'friday' => false, 'saturday' => false, 'sunday' => false, 'start_date' => '2026-10-05', 'end_date' => '2026-10-05']);
    DB::table('gtfs_stop_times')->insert(['trip_id' => 'night-trip', 'stop_id' => 'night-stop', 'arrival_time' => '25:10:00', 'departure_time' => '25:10:00', 'stop_sequence' => 1]);

    $result = app(GtfsDepartureService::class)->getStaticDepartures('Night Stop');
    expect($result['departures'][0]['services'][0]['departure_at'])->toBe('2026-10-06T01:10:00+02:00')
        ->and($result['departures'][0]['departures'][0])->toBe(40);
});

test('tomorrows first service uses its own calendar exception and no prior day live estimate', function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->setTime(23, 55));
    config(['services.vrs.enabled' => true]);
    $this->mock(DisruptionService::class)->shouldReceive('getDisruptedLines')->andReturn([]);
    $this->mock(VrsRealtimeService::class, function ($mock) {
        $mock->shouldReceive('getTripUpdates')->andReturn(['early-trip' => ['trip_id' => 'early-trip']]);
        $mock->shouldNotReceive('getDelayForTripAtStop');
    });
    DB::table('gtfs_stops')->insert(['stop_id' => 'early-stop', 'stop_name' => 'Early Stop', 'stop_lat' => 50.94, 'stop_lng' => 6.94, 'location_type' => 0]);
    DB::table('gtfs_routes')->insert(['route_id' => 'early-route', 'route_short_name' => '1', 'route_type' => 0]);
    DB::table('gtfs_trips')->insert(['trip_id' => 'early-trip', 'route_id' => 'early-route', 'service_id' => 'early-service', 'trip_headsign' => 'Destination']);
    DB::table('gtfs_calendar_dates')->insert(['service_id' => 'early-service', 'date' => '2026-10-07', 'exception_type' => 1]);
    DB::table('gtfs_stop_times')->insert(['trip_id' => 'early-trip', 'stop_id' => 'early-stop', 'arrival_time' => '00:05:00', 'departure_time' => '00:05:00', 'stop_sequence' => 1]);

    $result = app(GtfsDepartureService::class)->getStaticDepartures('Early Stop');
    expect($result['departures'][0]['services'][0]['departure_at'])->toBe('2026-10-07T00:05:00+02:00')
        ->and($result['departures'][0]['services'][0]['time_basis'])->toBe('scheduled')
        ->and($result['departures'][0]['departures'][0])->toBe(10);
});
