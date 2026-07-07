<?php

use App\Models\Gtfs\GtfsStop;
use App\Models\User;
use App\Services\DisruptionService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\JourneyResult;

/**
 * When the user is standing at a stop, the planner should originate from the
 * stop so an imminent departure isn't dropped behind an assumed access walk.
 */
beforeEach(function () {
    GtfsStop::create([
        'stop_id' => 'stop-a',
        'stop_name' => 'Test Platform',
        'stop_lat' => 50.9500,
        'stop_lng' => 6.9200,
        'location_type' => 0,
    ]);

    $this->mock(
        DisruptionService::class,
        fn ($m) => $m->shouldReceive('getLineDisruptions')->andReturn([]),
    );
});

/** Mock the router so plan() records the origin it was handed. */
function captureOrigin(): object
{
    $box = new stdClass;
    $box->from = null;

    test()->mock(RouteService::class, function ($m) use ($box) {
        $m->shouldReceive('plan')->andReturnUsing(function (GeoPoint $from) use ($box) {
            $box->from = $from;

            return new JourneyResult([], 'transitous');
        });
    });

    return $box;
}

test('plans from the stop when the user is standing at it', function () {
    $box = captureOrigin();
    $this->actingAs(User::factory()->onboarded()->create());

    // ~33 m from the stop — GPS "on the platform".
    $this->getJson('/api/journey?to_lat=50.90&to_lng=6.95&from_lat=50.9503&from_lng=6.9200')
        ->assertOk();

    expect($box->from)->not->toBeNull();
    expect(round($box->from->lat, 4))->toBe(50.95);
    expect(round($box->from->lng, 4))->toBe(6.92);
});

test('plans from the real origin when no stop is within reach', function () {
    $box = captureOrigin();
    $this->actingAs(User::factory()->onboarded()->create());

    // ~1.4 km from the only stop — well beyond the "at the stop" radius.
    $this->getJson('/api/journey?to_lat=50.90&to_lng=6.95&from_lat=50.9600&from_lng=6.9350')
        ->assertOk();

    expect(round($box->from->lat, 4))->toBe(50.96);
    expect(round($box->from->lng, 4))->toBe(6.935);
});
