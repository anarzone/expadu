<?php

use App\Services\GtfsDepartureService;
use App\Services\KvbApiService;
use App\Services\TimetableService;
use App\Transit\MockSample\SampleKvb;
use App\Transit\MockSample\SampleTimetable;
use Illuminate\Support\Facades\Cache;

test('the mock sample boards and lift records are never bound outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(app(TimetableService::class))->not->toBeInstanceOf(SampleTimetable::class)
        ->and(app(KvbApiService::class))->not->toBeInstanceOf(SampleKvb::class);
});

test('without the local mock clock the sample reads the real board and lift feed', function () {
    $gtfs = Mockery::mock(GtfsDepartureService::class);
    $gtfs->shouldReceive('getDeparturesNearby')->once()->andReturn(['departures' => [], 'source' => 'gtfs']);

    // The sample's own stop: still the real board, because this isn't a
    // local request with the mock clock.
    (new SampleTimetable($gtfs))->pointBoard(['id' => null, 'name' => 'Neumarkt', 'lat' => 50.93576, 'lng' => 6.94811]);

    Cache::put('kvb:elevator_disruptions', [['id' => 'lift-1', 'label' => 'Neumarkt lift', 'stop_area' => '2', 'info' => '', 'lat' => 50.9, 'lng' => 6.9, 'timestamp' => '']], 60);

    expect((new SampleKvb)->knownElevatorDisruptions())->toHaveCount(1);
});
