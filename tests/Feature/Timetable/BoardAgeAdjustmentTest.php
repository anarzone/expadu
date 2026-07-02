<?php

use App\Services\TimetableService;

/**
 * The SWR caches store minutes-until-departure AS FETCHED; serving them
 * without re-anchoring froze the board (the "times never update" bug).
 * adjustForAge() must shift cached minutes by the cache age and drop rows
 * that already departed.
 */
test('cached board minutes are re-anchored to now by cache age', function () {
    $service = app(TimetableService::class);
    $adjust = new ReflectionMethod($service, 'adjustForAge');

    $board = [
        'stop_name' => 'Neumarkt',
        'walk_min' => 4,
        'source' => 'gtfs_static',
        'fetched_at' => time() - 125, // cached just over two minutes ago
        'departures' => [
            ['line' => '1', 'minutes' => [5, 15, 25], 'cancelled' => false],
            ['line' => '9', 'minutes' => [1], 'cancelled' => false],
            ['line' => '7', 'minutes' => [], 'cancelled' => true],
        ],
    ];

    $out = $adjust->invoke($service, $board);

    // 2 full minutes elapsed: 5→3, 15→13, 25→23.
    expect($out['departures'][0]['minutes'])->toBe([3, 13, 23])
        // the 1-minute departure left the station — row dropped
        ->and(collect($out['departures'])->pluck('line')->all())->toBe(['1', '7'])
        // cancelled rows stay visible regardless of minutes
        ->and($out['departures'][1]['cancelled'])->toBeTrue()
        // the stamp is internal — never reaches the client
        ->and($out)->not->toHaveKey('fetched_at');
});

test('a fresh board passes through untouched', function () {
    $service = app(TimetableService::class);
    $adjust = new ReflectionMethod($service, 'adjustForAge');

    $board = [
        'stop_name' => 'Neumarkt',
        'source' => 'trias_rt',
        'fetched_at' => time() - 20, // inside the same minute
        'departures' => [
            ['line' => '1', 'minutes' => [5, 15], 'cancelled' => false],
        ],
    ];

    $out = $adjust->invoke($service, $board);

    expect($out['departures'][0]['minutes'])->toBe([5, 15]);
});

test('a null board stays null', function () {
    $service = app(TimetableService::class);
    $adjust = new ReflectionMethod($service, 'adjustForAge');

    expect($adjust->invoke($service, null))->toBeNull();
});
