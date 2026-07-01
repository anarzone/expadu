<?php

use App\Enums\TransportMode;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\TravelTimes;

beforeEach(function () {
    $this->origin = new GeoPoint(50.94, 6.95);
    $this->dests = [new GeoPoint(50.95, 6.96), new GeoPoint(50.99, 7.05)];
});

/**
 * A RouteService stub that records which modes were queried and returns a
 * per-mode minutes array.
 *
 * @param  array<string, list<int|null>>  $byMode
 */
function recordingRoutes(array &$calls, array $byMode): RouteService
{
    $mock = Mockery::mock(RouteService::class);
    $mock->shouldReceive('travelMatrix')->andReturnUsing(function ($origin, $destinations, $mode = 'BIKE') use (&$calls, $byMode) {
        $calls[] = $mode;

        return $byMode[$mode] ?? array_fill(0, count($destinations), null);
    });

    return $mock;
}

test('bike preference uses the bike matrix', function () {
    $calls = [];
    $minutes = (new TravelTimes(recordingRoutes($calls, ['BIKE' => [5, 20]])))
        ->minutes(TransportMode::Bike, $this->origin, $this->dests);

    expect($minutes)->toBe([5, 20]);
    expect($calls)->toBe(['BIKE']);
});

test('no preference defaults to the walk matrix (what the From control shows)', function () {
    $calls = [];
    $minutes = (new TravelTimes(recordingRoutes($calls, ['WALK' => [8, 30]])))
        ->minutes(null, $this->origin, $this->dests);

    expect($minutes)->toBe([8, 30]);
    expect($calls)->toBe(['WALK']); // bike never queried
});

test('transit preference uses bike as the at-a-glance street proxy', function () {
    $calls = [];
    $minutes = (new TravelTimes(recordingRoutes($calls, ['BIKE' => [5, 20]])))
        ->minutes(TransportMode::Transit, $this->origin, $this->dests);

    expect($minutes)->toBe([5, 20]);
    expect($calls)->toBe(['BIKE']);
});

test('walk preference uses only the walk matrix', function () {
    $calls = [];
    $minutes = (new TravelTimes(recordingRoutes($calls, ['WALK' => [8, 30]])))
        ->minutes(TransportMode::Walk, $this->origin, $this->dests);

    expect($minutes)->toBe([8, 30]);
    expect($calls)->toBe(['WALK']); // bike never queried
});

test('a long walk shows the real walk time — never the bike figure under a walk label', function () {
    $calls = [];
    // The far destination is a 50-min walk. It must stay 50 (the selected mode),
    // not silently borrow the 15-min bike time — that read as the wrong mode.
    $minutes = (new TravelTimes(recordingRoutes($calls, ['WALK' => [8, 50], 'BIKE' => [3, 15]])))
        ->minutes(TransportMode::Walk, $this->origin, $this->dests);

    expect($minutes)->toBe([8, 50]);
    expect($calls)->toBe(['WALK']); // bike never queried
});

test('no destinations means no routing call', function () {
    $calls = [];
    $minutes = (new TravelTimes(recordingRoutes($calls, [])))
        ->minutes(TransportMode::Bike, $this->origin, []);

    expect($minutes)->toBe([]);
    expect($calls)->toBe([]);
});
