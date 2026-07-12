<?php

use App\Models\Gtfs\GtfsStopTime;
use App\Services\VrsRealtimeService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config(['services.vrs.enabled' => true]);
    config(['services.vrs.gtfsrt_url' => 'https://gtfs-rt-test.vrs.de:4443/buffer/tripUpdate.buf']);
});

it('returns empty when disabled', function () {
    config(['services.vrs.enabled' => false]);

    $service = app(VrsRealtimeService::class);

    expect($service->getTripUpdates())->toBe([]);
});

it('returns empty on HTTP failure', function () {
    Http::fake([
        '*gtfs-rt-test.vrs.de*' => Http::response('', 500),
    ]);

    $service = app(VrsRealtimeService::class);

    expect($service->getTripUpdates())->toBe([]);
});

it('returns empty on network error', function () {
    Http::fake([
        '*gtfs-rt-test.vrs.de*' => fn () => throw new Exception('Connection timeout'),
    ]);

    $service = app(VrsRealtimeService::class);

    expect($service->getTripUpdates())->toBe([]);
});

it('caches results and does not re-fetch within 30s', function () {
    Http::fake([
        '*gtfs-rt-test.vrs.de*' => Http::response('', 200),
    ]);

    $service = app(VrsRealtimeService::class);

    $service->getTripUpdates();
    $service->getTripUpdates();

    Http::assertSentCount(1);
});

it('gets delay for trip at stop from cached data', function () {
    // Pre-populate cache with parsed trip updates
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-001' => [
            'trip_id' => 'trip-001',
            'route_id' => '1',
            'cancelled' => false,
            'stops' => [
                '100' => ['delay' => 300, 'skipped' => false, 'sequence' => 1],
                '200' => ['delay' => 180, 'skipped' => false, 'sequence' => 2],
            ],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    $result = $service->getDelayForTripAtStop('trip-001', '100');
    expect($result)->not->toBeNull()
        ->and($result['delay'])->toBe(300)
        ->and($result['cancelled'])->toBeFalse()
        ->and($result['skipped'])->toBeFalse();
});

it('returns null for unknown trip', function () {
    Cache::put('vrs_gtfsrt_trip_updates', [], 30);

    $service = app(VrsRealtimeService::class);

    expect($service->getDelayForTripAtStop('unknown', '100'))->toBeNull();
});

it('returns cancelled status for cancelled trip', function () {
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-cancelled' => [
            'trip_id' => 'trip-cancelled',
            'route_id' => '7',
            'cancelled' => true,
            'stops' => [],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    $result = $service->getDelayForTripAtStop('trip-cancelled', '100');
    expect($result['cancelled'])->toBeTrue();
});

it('propagates delay when stop has no update', function () {
    GtfsStopTime::create([
        'trip_id' => 'trip-prop',
        'stop_id' => '300',
        'arrival_time' => '12:10:00',
        'departure_time' => '12:10:00',
        'stop_sequence' => 3,
    ]);
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-prop' => [
            'trip_id' => 'trip-prop',
            'route_id' => '5',
            'cancelled' => false,
            'stops' => [
                '100' => ['delay' => 120, 'skipped' => false, 'sequence' => 1],
                '200' => ['delay' => 180, 'skipped' => false, 'sequence' => 2],
            ],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    // Stop 300 not in the updates — should propagate last known delay
    $result = $service->getDelayForTripAtStop('trip-prop', '300');
    expect($result)->not->toBeNull()
        ->and($result['delay'])->toBe(180) // last known delay from sequence 2
        ->and($result['skipped'])->toBeFalse();
});

it('does not propagate a later stop delay backwards to an earlier target stop', function () {
    GtfsStopTime::create([
        'trip_id' => 'trip-mid',
        'stop_id' => '150',
        'arrival_time' => '12:05:00',
        'departure_time' => '12:05:00',
        'stop_sequence' => 2,
    ]);
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-mid' => [
            'trip_id' => 'trip-mid',
            'route_id' => '5',
            'cancelled' => false,
            'stops' => [
                '100' => ['delay' => 120, 'skipped' => false, 'sequence' => 1],
                '200' => ['delay' => 480, 'skipped' => false, 'sequence' => 3],
            ],
        ],
    ], 30);

    $result = app(VrsRealtimeService::class)->getDelayForTripAtStop('trip-mid', '150');

    expect($result['delay'])->toBe(120);
});

it('gets general delay for a trip using max delay', function () {
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-gen' => [
            'trip_id' => 'trip-gen',
            'route_id' => '5',
            'cancelled' => false,
            'stops' => [
                '100' => ['delay' => 60, 'skipped' => false, 'sequence' => 1],
                '200' => ['delay' => 300, 'skipped' => false, 'sequence' => 2],
                '300' => ['delay' => 120, 'skipped' => false, 'sequence' => 3],
            ],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    $result = $service->getDelayForTrip('trip-gen');
    expect($result['delay'])->toBe(300)
        ->and($result['cancelled'])->toBeFalse();
});

it('returns cancelled for general trip delay when trip is cancelled', function () {
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-c' => [
            'trip_id' => 'trip-c',
            'route_id' => '9',
            'cancelled' => true,
            'stops' => [],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    $result = $service->getDelayForTrip('trip-c');
    expect($result['cancelled'])->toBeTrue();
});

it('skips skipped stops when calculating max delay', function () {
    Cache::put('vrs_gtfsrt_trip_updates', [
        'trip-skip' => [
            'trip_id' => 'trip-skip',
            'route_id' => '3',
            'cancelled' => false,
            'stops' => [
                '100' => ['delay' => 60, 'skipped' => false, 'sequence' => 1],
                '200' => ['delay' => 999, 'skipped' => true, 'sequence' => 2],
                '300' => ['delay' => 120, 'skipped' => false, 'sequence' => 3],
            ],
        ],
    ], 30);

    $service = app(VrsRealtimeService::class);

    $result = $service->getDelayForTrip('trip-skip');
    expect($result['delay'])->toBe(120); // skipped stop's delay excluded
});
