<?php

use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\JourneyResult;
use App\Transit\MockSample\SampleRoutes;
use Carbon\CarbonImmutable;

test('the mock sample ways are never bound outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(app(RouteService::class))->not->toBeInstanceOf(SampleRoutes::class);
});

test('without the local mock clock the sample defers to the real router', function () {
    $real = Mockery::mock(RouteService::class);
    $answer = new JourneyResult([], 'transitous');
    $real->shouldReceive('plan')->once()->andReturn($answer);

    // The sample's own destination, at the sample's moment: still the real
    // router, because this isn't a local request with the mock clock.
    $result = (new SampleRoutes($real))->plan(
        new GeoPoint(50.9361, 6.9473),
        new GeoPoint(50.96103, 7.0048),
        CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin'),
    );

    expect($result)->toBe($answer);
});
