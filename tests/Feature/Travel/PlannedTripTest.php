<?php

use App\Day\DayProjection;
use App\Models\CityNews;
use App\Models\ComposerWorkspace;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Notifications\PlannedTripChangeOfPlan;
use App\Notifications\PlannedTripLeaveReminder;
use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

// "Add to plan": the way a person picks for one of their own plans. Only
// their own plans on that day take one, it stays a bounded journey, only
// they can change or remove it, Leave now leads with it while it leaves the
// margin, and its reminder goes once, when they asked for it. "We keep
// watching it": a Change of plan goes to its owner once per severity, from
// live data only, by the plan's id only; rain on a long walk only when
// another way walks half as much.

const PLANNED_DAY = '2026-10-21';

function plannedTripJourney(string $board, string $alight, string $leave, string $there): array
{
    $at = fn (string $t) => CarbonImmutable::parse(PLANNED_DAY." {$t}", 'Europe/Berlin');
    $start = new Place('', new GeoPoint(50.9361, 6.9473));
    $stop = new Place('Köln Neumarkt', new GeoPoint(50.9363, 6.9469));
    $get = new Place('Köln Mülheim Wiener Platz', new GeoPoint(50.9610, 7.0040));
    $end = new Place('', new GeoPoint(50.96103, 7.0048));

    return (new Journey([
        new Leg('walk', $start, $stop, $at($leave), $at($board), 4),
        new Leg('tram', $stop, $get, $at($board), $at($alight), 28, '4', 'Schlebusch'),
        new Leg('walk', $get, $end, $at($alight), $at($there), 6),
    ], $at($leave), $at($there), (int) $at($leave)->diffInMinutes($at($there)), 0))->toArray();
}

/** A confirmed day with the Blue Card appointment at 14:00 at Wiener Platz. */
function plannedTripDay(User $user, string $id = 'blue-card'): void
{
    $at = fn (string $t) => CarbonImmutable::parse(PLANNED_DAY." {$t}", 'Europe/Berlin')->toIso8601String();

    ComposerWorkspace::query()->updateOrCreate(['user_id' => $user->id], [
        'revision' => 1,
        'state' => [
            'approval' => 'ask', 'active' => 'initial', 'focus' => null, 'draft' => null, 'versions' => [],
            'conversations' => [['id' => 'initial', 'title' => 'New conversation', 'created_at' => now()->toIso8601String(), 'messages' => []]],
            'plans' => [PLANNED_DAY => ['date' => PLANNED_DAY, 'constraints' => ['window_start' => PLANNED_DAY.'T08:00:00', 'window_end' => PLANNED_DAY.'T23:59:00'], 'slots' => [[
                'id' => $id, 'type' => 'appointment', 'name' => 'Blue Card appointment', 'category' => 'appointment', 'swappable' => false,
                'start_time' => '14:00', 'end_time' => '14:30', 'start_at' => $at('14:00'), 'end_at' => $at('14:30'),
                'location' => 'Mülheim', 'lat' => 50.96103, 'lng' => 7.0048,
            ]]]],
        ],
    ]);
}

function planTripBody(array $journey, string $target = 'blue-card', bool $remind = false): array
{
    return ['date' => PLANNED_DAY, 'target_id' => $target, 'journey' => $journey, 'remind' => $remind];
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(PLANNED_DAY.' 13:00', 'Europe/Berlin'));
    $this->user = User::factory()->onboarded()->create();
    plannedTripDay($this->user);
});

test('a planned way is kept for one of your own plans, one per plan', function () {
    $this->actingAs($this->user)
        ->postJson(route('api.travel.planned-trips.store'), planTripBody(plannedTripJourney('13:24', '13:52', '13:20', '13:58')))
        ->assertCreated()
        ->assertJsonPath('planned.depart_time', '13:20')
        ->assertJsonPath('planned.ride.time', '13:24');

    // Picking another way for the same plan replaces it.
    $this->actingAs($this->user)
        ->postJson(route('api.travel.planned-trips.store'), planTripBody(plannedTripJourney('13:14', '13:42', '13:10', '13:48'), remind: true))
        ->assertCreated()
        ->assertJsonPath('planned.depart_time', '13:10')
        ->assertJsonPath('planned.remind', true);

    expect(PlannedTrip::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(PlannedTrip::query()->first()->title)->toBe('Blue Card appointment');
});

test('only your own plans on that day take a way', function () {
    $journey = plannedTripJourney('13:14', '13:42', '13:10', '13:48');

    // Not on your day.
    $this->actingAs($this->user)
        ->postJson(route('api.travel.planned-trips.store'), planTripBody($journey, 'someone-elses-plan'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('target_id');

    // Another person's plan, same id, same day: still not yours.
    $other = User::factory()->onboarded()->create();
    plannedTripDay($other, 'their-appointment');
    $this->actingAs($this->user)
        ->postJson(route('api.travel.planned-trips.store'), planTripBody($journey, 'their-appointment'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('target_id');

    expect(PlannedTrip::query()->count())->toBe(0);
});

test('the planned way stays a bounded journey', function () {
    $journey = plannedTripJourney('13:14', '13:42', '13:10', '13:48');

    $badMode = $journey;
    $badMode['legs'][1]['mode'] = 'teleport';
    $backwards = $journey;
    $backwards['arrive_at'] = $journey['depart_at'];
    $huge = $journey;
    $huge['legs'][1]['intermediate_stops'] = array_fill(0, 150, ['name' => str_repeat('x', 280), 'arrive_at' => $journey['depart_at'], 'arrive_time' => '13:20', 'lat' => 50.9, 'lng' => 6.9]);
    $huge['legs'][0]['polyline'] = str_repeat('a', 99000);
    $huge['legs'][2]['polyline'] = str_repeat('a', 99000);
    $huge['legs'][1]['polyline'] = str_repeat('a', 99000);

    foreach ([$badMode, $backwards, $huge] as $bad) {
        $this->actingAs($this->user)
            ->postJson(route('api.travel.planned-trips.store'), planTripBody($bad))
            ->assertUnprocessable();
    }

    expect(PlannedTrip::query()->count())->toBe(0);
});

test('only you can change or remove your planned way', function () {
    $trip = PlannedTrip::query()->create([
        'user_id' => $this->user->id, 'date' => PLANNED_DAY, 'target_id' => 'blue-card', 'title' => 'Blue Card appointment',
        'journey' => plannedTripJourney('13:14', '13:42', '13:10', '13:48'),
        'depart_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:10', 'Europe/Berlin'),
        'arrive_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:48', 'Europe/Berlin'),
    ]);
    $other = User::factory()->onboarded()->create();

    $this->actingAs($other)->patchJson(route('api.travel.planned-trips.update', $trip->id), ['remind' => true])->assertNotFound();
    $this->actingAs($other)->deleteJson(route('api.travel.planned-trips.destroy', $trip->id))->assertNotFound();
    expect($trip->fresh()->remind)->toBeFalse();

    $this->actingAs($this->user)->patchJson(route('api.travel.planned-trips.update', $trip->id), ['remind' => true])
        ->assertOk()->assertJsonPath('planned.remind', true)->assertJsonPath('planned.remind_at', '13:05');
    $this->actingAs($this->user)->deleteJson(route('api.travel.planned-trips.destroy', $trip->id))->assertNoContent();
    expect(PlannedTrip::query()->count())->toBe(0);
});

test('Leave now leads with your way while it leaves the margin, and names it when it does not', function () {
    // The router answers with the 13:14 and the 13:24 (the official
    // appointment needs ten minutes in hand).
    $routes = new class implements RouteService
    {
        public function withTimeBudget(float $seconds): static
        {
            return $this;
        }

        public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
        {
            return new JourneyResult([
                Journey::fromArray(plannedTripJourney('13:14', '13:42', '13:10', '13:48')),
                Journey::fromArray(plannedTripJourney('13:24', '13:52', '13:20', '13:58')),
            ], 'transitous');
        }

        public function geocode(string $query, ?GeoPoint $bias = null): array
        {
            return [];
        }

        public function reverseGeocode(GeoPoint $point): ?Place
        {
            return null;
        }

        public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
        {
            return [];
        }
    };
    $this->app->instance(RouteService::class, $routes);
    Redis::setex("confirmed_location:{$this->user->id}", 3600, json_encode(['lat' => 50.9361, 'lng' => 6.9473, 'name' => 'Neumarkt', 'at' => now()->timestamp]));
    $this->travelTo(CarbonImmutable::parse(PLANNED_DAY.' 13:05', 'Europe/Berlin'));

    // Your 13:24 leaves only 2 minutes: the 13:14 leads, and yours is named.
    $plan = fn (string $board, string $alight, string $leave, string $there) => PlannedTrip::query()->updateOrCreate(
        ['user_id' => $this->user->id, 'date' => PLANNED_DAY, 'target_id' => 'blue-card'],
        ['title' => 'Blue Card appointment', 'journey' => plannedTripJourney($board, $alight, $leave, $there),
            'depart_at' => CarbonImmutable::parse(PLANNED_DAY." {$leave}", 'Europe/Berlin'),
            'arrive_at' => CarbonImmutable::parse(PLANNED_DAY." {$there}", 'Europe/Berlin')],
    );
    $plan('13:24', '13:52', '13:20', '13:58');
    $day = app(DayProjection::class)->for($this->user, PLANNED_DAY);
    expect($day['next_trip']['leave_time'])->toBe('13:10')
        ->and($day['next_trip']['reason'])->toBe('earlier_for_margin')
        ->and($day['next_trip']['skipped']['ride']['time'])->toBe('13:24')
        ->and($day['next_trip']['skipped']['in_plan'])->toBeTrue()
        ->and($day['items'][0]['planned']['depart_time'])->toBe('13:20');

    // Your 13:14 leaves the margin: it is the trip.
    $plan('13:14', '13:42', '13:10', '13:48');
    $day = app(DayProjection::class)->for($this->user, PLANNED_DAY);
    expect($day['next_trip']['reason'])->toBe('planned')
        ->and($day['next_trip']['leave_time'])->toBe('13:10');

    // Someone else's planned way never reaches your day.
    $other = User::factory()->onboarded()->create();
    plannedTripDay($other);
    expect(app(DayProjection::class)->for($other, PLANNED_DAY)['items'][0]['planned'])->toBeNull();
});

test('the reminder goes once, five minutes before the way leaves, only when switched on', function () {
    Notification::fake();
    $make = fn (bool $remind, string $target) => PlannedTrip::query()->create([
        'user_id' => $this->user->id, 'date' => PLANNED_DAY, 'target_id' => $target, 'title' => 'Blue Card appointment',
        'journey' => plannedTripJourney('13:14', '13:42', '13:10', '13:48'), 'remind' => $remind,
        'depart_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:10', 'Europe/Berlin'),
        'arrive_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:48', 'Europe/Berlin'),
    ]);
    $on = $make(true, 'blue-card');
    $make(false, 'other-plan');

    $this->travelTo(CarbonImmutable::parse(PLANNED_DAY.' 13:03', 'Europe/Berlin'));
    $this->artisan('travel:send-leave-reminders')->assertSuccessful();
    Notification::assertNothingSent();

    $this->travelTo(CarbonImmutable::parse(PLANNED_DAY.' 13:05', 'Europe/Berlin'));
    $this->artisan('travel:send-leave-reminders')->assertSuccessful();
    $this->artisan('travel:send-leave-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlannedTripLeaveReminder::class, 1);
    expect($on->fresh()->reminded_at)->not->toBeNull();
});

test('a planned way goes the day after', function () {
    PlannedTrip::query()->create([
        'user_id' => $this->user->id, 'date' => PLANNED_DAY, 'target_id' => 'blue-card', 'title' => 'Blue Card appointment',
        'journey' => plannedTripJourney('13:14', '13:42', '13:10', '13:48'),
        'depart_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:10', 'Europe/Berlin'),
        'arrive_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:48', 'Europe/Berlin'),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-10-22 23:00', 'Europe/Berlin'));
    $this->artisan('model:prune', ['--model' => [PlannedTrip::class]])->assertSuccessful();
    expect(PlannedTrip::query()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-10-23 04:00', 'Europe/Berlin'));
    $this->artisan('model:prune', ['--model' => [PlannedTrip::class]])->assertSuccessful();
    expect(PlannedTrip::query()->count())->toBe(0);
});

/** The ride as the router answers it now: late by $late minutes, or cancelled. */
function watchedRide(string $board, string $alight, string $leave, string $there, int $late = 0, bool $cancelled = false, bool $live = true): Journey
{
    $at = fn (string $t) => CarbonImmutable::parse(PLANNED_DAY." {$t}", 'Europe/Berlin');
    $start = new Place('', new GeoPoint(50.9361, 6.9473));
    $stop = new Place('Köln Neumarkt', new GeoPoint(50.9363, 6.9469));
    $get = new Place('Köln Mülheim Wiener Platz', new GeoPoint(50.9610, 7.0040));
    $end = new Place('', new GeoPoint(50.96103, 7.0048));

    return new Journey([
        new Leg('walk', $start, $stop, $at($leave), $at($board), 4),
        new Leg('tram', $stop, $get, $at($board)->addMinutes($late), $at($alight)->addMinutes($late), 28, '4', 'Schlebusch',
            scheduledDepartAt: $at($board), scheduledArriveAt: $at($alight), delayMin: $live ? $late : null, realtime: $live, cancelled: $cancelled),
        new Leg('walk', $get, $end, $at($alight)->addMinutes($late), $at($there)->addMinutes($late), 6),
    ], $at($leave), $at($there)->addMinutes($late), 38 + $late, 0);
}

/** A router that answers with whatever the test says the feed has now. */
function watchedRoutes(): object
{
    $routes = new class implements RouteService
    {
        /** @var list<Journey> */
        public array $journeys = [];

        public function withTimeBudget(float $seconds): static
        {
            return $this;
        }

        public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
        {
            return new JourneyResult($this->journeys, 'motis');
        }

        public function geocode(string $query, ?GeoPoint $bias = null): array
        {
            return [];
        }

        public function reverseGeocode(GeoPoint $point): ?Place
        {
            return null;
        }

        public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
        {
            return [];
        }
    };
    app()->instance(RouteService::class, $routes);
    // A dry day unless a test says otherwise: the forecast is never fetched.
    rainAt(0.0, 0);

    return $routes;
}

function watchedTrip(User $user): PlannedTrip
{
    return PlannedTrip::query()->create([
        'user_id' => $user->id, 'date' => PLANNED_DAY, 'target_id' => 'blue-card', 'title' => 'Blue Card appointment',
        'journey' => plannedTripJourney('13:14', '13:42', '13:10', '13:48'),
        'depart_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:10', 'Europe/Berlin'),
        'arrive_at' => CarbonImmutable::parse(PLANNED_DAY.' 13:48', 'Europe/Berlin'),
        'planned_at' => CarbonImmutable::parse(PLANNED_DAY.' 12:30', 'Europe/Berlin'),
    ]);
}

test('a cancelled ride sends its owner one Change of plan, with the way instead, by the plan id only', function () {
    Notification::fake();
    $routes = watchedRoutes();
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', cancelled: true), watchedRide('13:24', '13:52', '13:20', '13:58')];
    $trip = watchedTrip($this->user);
    // Someone else's way on the same tram, on a day without that plan: not theirs to hear about.
    $other = User::factory()->onboarded()->create();
    watchedTrip($other);

    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();

    Notification::assertSentToTimes($this->user, PlannedTripChangeOfPlan::class, 1);
    Notification::assertNotSentTo($other, PlannedTripChangeOfPlan::class);
    Notification::assertSentTo($this->user, PlannedTripChangeOfPlan::class, function (PlannedTripChangeOfPlan $change) {
        $data = $change->toArray($this->user);

        return $data['body'] === 'Your 13:14 tram 4 is cancelled. Instead: leave 13:20 for tram 4 at 13:24, there 13:58, only 2 min before 14:00.'
            && $data['url'] === '/timetable?view=journey&activity=blue-card';
    });
    expect($trip->fresh()->alerted_kind)->toBe('cancelled');
});

test('lateness counts only from live times eating the margin; a worse change is sent again, a milder one never', function () {
    Notification::fake();
    $routes = watchedRoutes();
    $trip = watchedTrip($this->user);

    // Two minutes late still leaves the official ten; timetable times say nothing.
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', late: 2)];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', late: 9, live: false)];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertNothingSent();

    // Nine minutes late leaves three of the ten an official appointment wants.
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', late: 9)];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlannedTripChangeOfPlan::class, 1);
    expect($trip->fresh()->alerted_kind)->toBe('late');

    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', cancelled: true)];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48', late: 9)];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlannedTripChangeOfPlan::class, 2);
    expect($trip->fresh()->alerted_kind)->toBe('cancelled');
});

test('a KVB notice for its line counts only when published after the way was picked', function () {
    Notification::fake();
    $routes = watchedRoutes();
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48')];
    watchedTrip($this->user);
    $notice = fn (string $title, string $at) => CityNews::query()->create([
        'title' => $title, 'summary' => '', 'source' => 'kvb', 'source_url' => 'https://www.kvb.koeln/#'.md5($title), 'category' => 'transit',
        'affected_lines' => ['4'], 'severity' => 'major', 'published_at' => CarbonImmutable::parse(PLANNED_DAY." {$at}", 'Europe/Berlin'),
    ]);

    $notice('Linie 4: Bauarbeiten am Ebertplatz', '09:00');
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertNothingSent();

    $notice('Linie 4: Störung zwischen Neumarkt und Wiener Platz', '12:55');
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertSentTo($this->user, PlannedTripChangeOfPlan::class, fn (PlannedTripChangeOfPlan $change) => $change->toArray($this->user)['body'] === 'KVB notice for tram 4: Linie 4: Störung zwischen Neumarkt und Wiener Platz');
});

/** A drier way: a minute to the bus, two from it, there 13:45. */
function busWay(): Journey
{
    $at = fn (string $t) => CarbonImmutable::parse(PLANNED_DAY." {$t}", 'Europe/Berlin');
    $start = new Place('', new GeoPoint(50.9361, 6.9473));
    $stop = new Place('Köln Heumarkt', new GeoPoint(50.9360, 6.9500));
    $off = new Place('Köln Mülheim Wiener Platz', new GeoPoint(50.9610, 7.0040));
    $end = new Place('', new GeoPoint(50.96103, 7.0048));

    return new Journey([
        new Leg('walk', $start, $stop, $at('13:16'), $at('13:17'), 1),
        new Leg('bus', $stop, $off, $at('13:17'), $at('13:43'), 26, '150', 'Mülheim'),
        new Leg('walk', $off, $end, $at('13:43'), $at('13:45'), 2),
    ], $at('13:16'), $at('13:45'), 29, 0);
}

/** The city's forecast for the sample day: this much rain at 13:00. */
function rainAt(float $mm, int $chance): void
{
    $hours = array_map(fn (int $h) => [
        'time' => CarbonImmutable::parse(PLANNED_DAY.sprintf(' %02d:00', $h), 'Europe/Berlin')->toIso8601String(),
        'precip' => $h === 13 ? $mm : 0.0,
        'precip_probability' => $h === 13 ? $chance : 10,
    ], range(10, 16));
    app()->instance(WeatherService::class, Mockery::mock(WeatherService::class, fn ($mock) => $mock->shouldReceive('getDayForecast')->andReturn(['hours' => $hours])));
}

test('rain on a long walk sends one Change of plan with a way that walks half as much, and only then', function () {
    Notification::fake();
    $routes = watchedRoutes();
    $trip = watchedTrip($this->user);

    // Dry, or rain without a better way: nothing.
    rainAt(0.0, 20);
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48'), busWay()];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    rainAt(1.4, 80);
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48')];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertNothingSent();

    // Rain on the 10-minute walk, and a way that walks 3: once.
    $routes->journeys = [watchedRide('13:14', '13:42', '13:10', '13:48'), busWay()];
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    $this->artisan('travel:watch-planned-trips')->assertSuccessful();
    Notification::assertSentToTimes($this->user, PlannedTripChangeOfPlan::class, 1);
    Notification::assertSentTo($this->user, PlannedTripChangeOfPlan::class, fn (PlannedTripChangeOfPlan $change) => $change->toArray($this->user)['body']
        === 'Rain on your 10-min walk · this way walks 3: leave 13:16 for bus 150 at 13:17, there 13:45.');
    expect($trip->fresh()->alerted_kind)->toBe('weather');
});
