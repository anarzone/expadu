<?php

use App\Models\Gtfs\GtfsStop;
use App\Models\User;
use App\Services\DisruptionService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;

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

/** A minimal one-leg transit journey departing at the given time. */
function transitAt(CarbonImmutable $departAt): Journey
{
    $leg = new Leg(
        mode: 'tram',
        from: new Place('Test Platform', new GeoPoint(50.9500, 6.9200), null),
        to: new Place('Destination', new GeoPoint(50.9000, 6.9500), null),
        departAt: $departAt,
        arriveAt: $departAt->addMinutes(15),
        durationMin: 15,
        lineName: '12',
        headsign: 'City',
    );

    return new Journey(legs: [$leg], departAt: $departAt, arriveAt: $departAt->addMinutes(15), durationMin: 15, transfers: 0);
}

/**
 * Mock the router to return one plan when it originates from the seeded stop
 * (~50.95) and another from anywhere else — so a test can hand the stop an
 * imminent departure the real-origin plan doesn't offer.
 */
function mockPlanByOrigin(Journey $fromStop, Journey $fromReal): void
{
    test()->mock(RouteService::class, function ($m) use ($fromStop, $fromReal) {
        $m->shouldReceive('plan')->andReturnUsing(function (GeoPoint $from) use ($fromStop, $fromReal) {
            $atStop = abs($from->lat - 50.9500) < 0.001 && abs($from->lng - 6.9200) < 0.001;

            return new JourneyResult([$atStop ? $fromStop : $fromReal], 'transitous');
        });
        $m->shouldReceive('reverseGeocode')->andReturnNull();
    });
}

test('surfaces the soonest catchable departure as a tight option', function () {
    $now = CarbonImmutable::now();
    // Real-origin plan only reaches a comfortable departure; the stop plan has an
    // imminent one the folded-in walk would otherwise drop.
    mockPlanByOrigin(transitAt($now->addMinutes(4)), transitAt($now->addMinutes(12)));
    $this->actingAs(User::factory()->onboarded()->create());

    // ~300 m from the stop — a short jog, well past the "at the stop" radius.
    $journeys = $this->getJson('/api/journey?to_lat=50.90&to_lng=6.95&from_lat=50.9527&from_lng=6.9200')
        ->assertOk()
        ->json('journeys');

    expect($journeys[0]['tight'])->toBeTrue();
    expect($journeys[0]['access_stop_name'])->toBe('Test Platform');
    expect($journeys[0]['access_walk_min'])->toBe(4);
    // The comfortable option is still listed behind it.
    expect($journeys[1] ?? [])->not->toHaveKey('tight');
});

test('does not surface a tight option the user could never reach in time', function () {
    $now = CarbonImmutable::now();
    // The stop's soonest departure is 2 min out; from ~445 m even a jog needs ~4.
    mockPlanByOrigin(transitAt($now->addMinutes(2)), transitAt($now->addMinutes(12)));
    $this->actingAs(User::factory()->onboarded()->create());

    $journeys = $this->getJson('/api/journey?to_lat=50.90&to_lng=6.95&from_lat=50.9540&from_lng=6.9200')
        ->assertOk()
        ->json('journeys');

    expect($journeys[0])->not->toHaveKey('tight');
});
