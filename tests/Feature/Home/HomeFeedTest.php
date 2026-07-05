<?php

use App\Composer\IntentWeights;
use App\Enums\LocationSource;
use App\Home\HomeFeed;
use App\Models\Event;
use App\Models\EventReminder;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Services\LocationContext;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\TravelTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function homeFeedUser(): User
{
    return User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'student',
        'is_eu' => true,
    ]);
}

function eventAtTonight(string $title, $startsAt, float $lat = 50.94, float $lng = 6.95): Event
{
    $event = Event::factory()->create(['title' => $title, 'starts_at' => $startsAt]);
    DB::statement('UPDATE events SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography WHERE id = ?', [$lng, $lat, $event->id]);

    return $event->refresh();
}

test('weather and intent weights resolve once across chips, tiles and rails', function () {
    $user = homeFeedUser();

    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->once()->andReturn(['rain_starts' => null]);
    });
    $this->mock(IntentWeights::class, function ($m) {
        $m->shouldReceive('for')->once()->andReturn([]);
    });

    $feed = app(HomeFeed::class);
    $chips = $feed->chips($user);
    $tiles = $feed->tiles($user);
    $rails = $feed->rails($user);

    expect($chips)->toBeArray()
        ->and($tiles)->toBeArray()
        ->and($rails)->toBeArray();
});

test('an imminent event the user intends becomes an urgent tile, not repeated in the rail', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'));
    $user = homeFeedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    $event = eventAtTonight('Imminent Meetup', now()->addMinutes(45));

    // The user cares about it (set a reminder) — that's what earns the urgent tile.
    EventReminder::create([
        'user_id' => $user->id,
        'event_id' => $event->id,
        'occurrence_start' => $event->starts_at,
        'offset_minutes' => 30,
        'remind_at' => now(),
    ]);

    $feed = app(HomeFeed::class);
    $tiles = collect($feed->tiles($user));
    $rails = collect($feed->rails($user));

    // Shows as an urgent "Right now" tile…
    expect($tiles->pluck('type'))->toContain('tonight_events');
    // …and is NOT repeated in the tonight rail (no triplication).
    expect($rails->firstWhere('key', 'tonight'))->toBeNull();
});

test('an imminent event the user has not saved earns no urgent tile (rail owns discovery)', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'));
    $user = homeFeedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    eventAtTonight('Random Meetup', now()->addMinutes(45));

    $feed = app(HomeFeed::class);

    // No reminder/attendance → not an act-now need; it lives in the tonight rail.
    expect(collect($feed->tiles($user))->pluck('type'))->not->toContain('tonight_events');
    expect(collect($feed->rails($user))->firstWhere('key', 'tonight'))->not->toBeNull();
});

test('a distant event stays a rail and earns no urgent tile', function () {
    $this->travelTo(CarbonImmutable::parse('2026-06-15 14:00', 'Europe/Berlin'));
    $user = homeFeedUser();
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.95, 'lng' => 6.92]);
    eventAtTonight('Evening Jazz', now()->addHours(5));

    $feed = app(HomeFeed::class);

    expect(collect($feed->tiles($user))->pluck('type'))->not->toContain('tonight_events');

    $tonight = collect($feed->rails($user))->firstWhere('key', 'tonight');
    expect($tonight)->not->toBeNull();
    expect(collect($tonight['cards'])->pluck('name'))->toContain('Evening Jazz');
});

test('only still-applicable open tasks reach the urgent tile and paperwork rail', function () {
    // Settled resident; the driving-licence question was never answered, so a
    // conditional task gated on license_country is now Unknown for them.
    $user = User::factory()->onboarded()->create([
        'veedel' => 'Ehrenfeld',
        'situation' => 'eu_employee',
        'is_eu' => true,
        'arrival_date' => now()->subYears(2),
        'profile_attributes' => ['housing_status' => 'long_term'],
    ]);

    // Both are freshly overdue (~30 days) against a ~730-day-old arrival, so the
    // applicable one earns a real tile — not softened away by the lapsed cap.
    $applies = Task::factory()->create([
        'key' => 'shared.test_applies',
        'title' => 'Definitely Applies',
        'is_published' => true,
        'applies_if' => [['housing_status' => ['long_term']]],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 700,
    ]);
    $unknown = Task::factory()->create([
        'key' => 'shared.test_unknown',
        'title' => 'Hinges On Unanswered',
        'is_published' => true,
        'applies_if' => [['license_country' => ['other']]],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 700,
    ]);

    // Both already materialised as open rows — PathGenerator never deletes them.
    UserTask::create(['user_id' => $user->id, 'task_id' => $applies->id]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $unknown->id]);

    $feed = app(HomeFeed::class);
    $deadlineTitles = collect($feed->tiles($user))->where('type', 'bureaucracy_deadline')->pluck('title');
    $paperwork = collect($feed->rails($user))->firstWhere('key', 'paperwork');
    $paperworkNames = $paperwork ? collect($paperwork['cards'])->pluck('name') : collect();

    // The applicable task still surfaces; the now-Unknown one leaks into neither.
    expect($deadlineTitles)->toContain('Definitely Applies')->not->toContain('Hinges On Unanswered');
    expect($paperworkNames)->toContain('Definitely Applies')->not->toContain('Hinges On Unanswered');
});

test('a long-lapsed deadline drops out of the urgent tiles while a fresh overdue one stays', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'arrival_date' => now()->subYears(2),
        'profile_attributes' => ['housing_status' => 'long_term'],
    ]);

    // Both applicable + overdue against a ~730-day-old arrival: one ~30 days
    // past (fresh), one ~1.5 years past (lapsed).
    $fresh = Task::factory()->create([
        'title' => 'Fresh Overdue', 'key' => 'x.fresh', 'is_published' => true,
        'applies_if' => [['housing_status' => ['long_term']]],
        'deadline_type' => 'days_since_arrival', 'deadline_days' => 700,
    ]);
    $lapsed = Task::factory()->create([
        'title' => 'Long Lapsed', 'key' => 'x.lapsed', 'is_published' => true,
        'applies_if' => [['housing_status' => ['long_term']]],
        'deadline_type' => 'days_since_arrival', 'deadline_days' => 180,
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $fresh->id]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $lapsed->id]);

    // The status caps: fresh stays a sharp countdown, lapsed softens (no number).
    $status = fn ($t) => $user->userTasks()->where('task_id', $t->id)->first()->deadline_status;
    expect($status($fresh)['urgency'])->toBe('overdue')
        ->and($status($lapsed)['urgency'])->toBe('lapsed')
        ->and($status($lapsed)['label'])->not->toContain('days');

    // Only the fresh miss reaches the urgent "Right now" tiles.
    $titles = collect(app(HomeFeed::class)->tiles($user))
        ->where('type', 'bureaucracy_deadline')->pluck('title');
    expect($titles)->toContain('Fresh Overdue')->not->toContain('Long Lapsed');
});

test('the kids chip fires for a user with a child_born_at attribute', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'profile_attributes' => ['child_born_at' => now()->subMonth()->toDateString()],
    ]);

    $chips = collect(app(HomeFeed::class)->chips($user));

    expect($chips->pluck('label'))->toContain('Something with the kids');
    // Plain label, icon carried separately (the frontend renders the Tabler icon).
    expect($chips->firstWhere('label', 'Something with the kids')['icon'] ?? null)->toBe('kids');
});

test('broken weather never frames the feed as rainy', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
    ]);

    $this->mock(WeatherService::class, function ($m) {
        // rain_starts is set, but the forecast didn't load — it must not count as rain.
        $m->shouldReceive('getForecast')->andReturn([
            'available' => false,
            'rain_starts' => 'now',
            'rain_summary' => null,
        ]);
        $m->shouldReceive('getCurrentWeather')->andReturn(['available' => false]);
    });

    $chips = collect(app(HomeFeed::class)->chips($user))->pluck('label');

    expect($chips)->not->toContain('Rainy-day picks');
});

test('a later-today rainy hour does not frame a dry morning as rainy', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
    ]);

    // Forecast loaded, but rain is OUTSIDE the near-term window: `rain_starts`
    // points at a later hour while the next hours are dry. The feed must follow
    // `rain_soon` (the window the weather widget shows), not `rain_starts`.
    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->andReturn([
            'available' => true,
            'rain_starts' => '21:00',
            'rain_soon' => false,
            'rain_summary' => 'Dry next 8 hours',
        ]);
    });

    $chips = collect(app(HomeFeed::class)->chips($user))->pluck('label');

    expect($chips)->not->toContain('Rainy-day picks');
});

test('rain in the near-term window frames the feed as rainy', function () {
    $user = User::factory()->onboarded()->create([
        'situation' => 'eu_employee',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
    ]);

    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->andReturn([
            'available' => true,
            'rain_starts' => '15:00',
            'rain_soon' => true,
            'rain_summary' => 'Rain from 15:00',
        ]);
    });

    $chips = collect(app(HomeFeed::class)->chips($user))->pluck('label');

    expect($chips)->toContain('Rainy-day picks');
});

test('rail place cards carry real travel minutes when the user has an origin', function () {
    $user = homeFeedUser();
    Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.9485, 'lng' => 6.9330]);

    // The user's remembered origin (what the From control shows).
    $this->mock(UserLocationService::class, function ($m) {
        $m->shouldReceive('context')->andReturn(new LocationContext(
            lat: 50.94, lng: 6.95, source: LocationSource::Live, label: 'Your location',
        ));
    });
    $this->mock(TravelTimes::class, function ($m) {
        $m->shouldReceive('minutes')->once()->andReturnUsing(
            fn ($mode, $origin, $destinations) => array_fill(0, count($destinations), 9),
        );
    });

    $rails = collect(app(HomeFeed::class)->rails($user));
    $card = $rails->flatMap(fn ($rail) => $rail['cards'])->firstWhere('name', 'Stadtgarten');

    expect($card['travel_min'])->toBe(9);
});

test('a routing outage degrades to a straight-line estimate, exactly like Places', function () {
    $user = homeFeedUser();
    Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.9485, 'lng' => 6.9330]);

    $this->mock(UserLocationService::class, function ($m) {
        $m->shouldReceive('context')->andReturn(new LocationContext(
            lat: 50.94, lng: 6.95, source: LocationSource::Live, label: 'Your location',
        ));
    });
    $this->mock(TravelTimes::class, function ($m) {
        $m->shouldReceive('minutes')->andThrow(new RuntimeException('routing down'));
    });

    $rails = collect(app(HomeFeed::class)->rails($user));
    $card = $rails->flatMap(fn ($rail) => $rail['cards'])->firstWhere('name', 'Stadtgarten');

    // The feed still renders AND still shows an estimated "min away".
    expect($card)->not->toBeNull();
    expect($card['travel_min'])->toBeInt()->toBeGreaterThan(0);
});

test('a brand-new user with no behaviour still gets a situation-driven feed', function () {
    // Cold start: zero user_events → IntentWeights is empty. The feed must be
    // fully situation-driven, never empty (the target user is a new arrival).
    $user = User::factory()->onboarded()->create([
        'situation' => 'student',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subDays(3),
    ]);
    Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.9485, 'lng' => 6.9330]);
    Spot::factory()->create(['name' => 'Museum Ludwig', 'category' => 'museum', 'veedel' => 'Altstadt-Nord', 'lat' => 50.9403, 'lng' => 6.9603]);

    $feed = app(HomeFeed::class);
    $chips = $feed->chips($user);
    $rails = collect($feed->rails($user));

    expect($chips)->not->toBeEmpty();
    expect($rails->flatMap(fn ($rail) => $rail['cards'])->count())->toBeGreaterThan(0);
});
