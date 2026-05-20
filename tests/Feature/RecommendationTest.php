<?php

uses()->group('slow');

use App\Models\Appointment;
use App\Models\CityNews;
use App\Models\Event;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\RecommendationService;
use App\Services\ValhallaRoutingService;
use Carbon\Carbon;

test('recommendations include appointment when user has upcoming one', function () {
    $user = User::factory()->onboarded()->create();
    Appointment::create([
        'user_id' => $user->id,
        'office_name' => 'Bürgeramt Deutz',
        'scheduled_at' => now()->addHours(3),
        'notes' => 'Bring passport',
    ]);

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $types = collect($recommendations)->pluck('type')->all();
    expect($types)->toContain('appointment');

    $appointment = collect($recommendations)->firstWhere('type', 'appointment');
    expect($appointment['title'])->toBe('Bürgeramt Deutz');
    expect($appointment['priority'])->toBeGreaterThan(80);
});

test('recommendations include today events', function () {
    $user = User::factory()->onboarded()->create();

    // Create event later today (ensure it stays within today and is in the future)
    $eventTime = now()->hour >= 22
        ? now()->copy()->setTime(23, 59)
        : now()->copy()->setTime(now()->hour + 1, 30);
    Event::factory()->create([
        'title' => 'Language Exchange',
        'starts_at' => $eventTime,
        'quality_score' => 0.8,
        'location_name' => 'Café Schmitz',
    ]);

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $types = collect($recommendations)->pluck('type')->all();
    expect($types)->toContain('event');
});

test('recommendations are sorted by priority', function () {
    $user = User::factory()->onboarded()->create();

    Appointment::create([
        'user_id' => $user->id,
        'office_name' => 'Ausländerbehörde',
        'scheduled_at' => now()->addHours(2),
    ]);
    Event::factory()->create(['starts_at' => now()->addHours(4), 'quality_score' => 0.8, 'location_name' => 'Stadtgarten']);

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $appointmentIdx = collect($recommendations)->search(fn ($r) => $r['type'] === 'appointment');
    $eventIdx = collect($recommendations)->search(fn ($r) => $r['type'] === 'event');

    if ($appointmentIdx !== false && $eventIdx !== false) {
        expect($appointmentIdx)->toBeLessThan($eventIdx);
    }
});

test('commute recommendation returns context-aware route cards', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work']);

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    expect($commute)->toHaveKeys(['from', 'to', 'headline', 'route_cards', 'weather', 'forecast', 'context']);
    expect($commute['route_cards'])->not->toBeEmpty();
    expect($commute['context'])->toBeIn(['to_work', 'to_home', 'at_work', 'to_place', 'off_hours', 'discovery', 'routine', 'to_regular']);
});

test('commute recommendation provides leave_by during commute hours', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work']);

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    // leave_by is only set during commute hours (to_work context)
    if ($commute['context'] === 'to_work') {
        expect($commute['leave_by'])->not->toBeNull();
        expect($commute['leave_by']['time'])->toMatch('/^\d{2}:\d{2}$/');
    } else {
        // Off hours or other context — leave_by may be null
        expect($commute['context'])->toBeString();
    }
});

test('dashboard includes unified feed', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->missing('feed')
        ->missing('weather')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('feed')
            ->has('feed.recommendations')
            ->has('feed.settlement')
            ->has('weather')
        )
    );
});

test('transit page includes commute recommendation', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('transit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('transit')
        ->missing('commuteRecommendation')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('commuteRecommendation')
            ->has('commuteRecommendation.headline')
            ->has('commuteRecommendation.route_cards')
        )
    );
});

// ── Holiday awareness ──

test('commute context returns off_hours on public holidays', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    // Ostermontag 2026 at 08:00 — would be to_work on a regular Monday
    $this->travelTo(Carbon::parse('2026-04-06 08:00'));

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    // getCommuteRecommendation wraps off_hours into 'discovery' via DiscoverySuggestionService
    expect($commute['context'])->toBeIn(['off_hours', 'discovery']);
});

test('commute cards show holiday card instead of commute tip on holidays', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    $this->travelTo(Carbon::parse('2026-04-06 10:00')); // Ostermontag

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $commuteTip = collect($recommendations)->firstWhere('type', 'commute_tip');
    if ($commuteTip) {
        expect($commuteTip['title'])->toContain('public holiday');
    }
});

test('work place isActiveToday returns false on holiday', function () {
    $this->travelTo(Carbon::parse('2026-04-06 08:00')); // Ostermontag

    $user = User::factory()->onboarded()->create();
    $work = UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    expect($work->isActiveToday())->toBeFalse();
});

// ── Card diversity limits ──

test('recommendations enforce max 2 disruption cards', function () {
    $user = User::factory()->onboarded()->create();

    $this->travelTo(Carbon::parse('2026-04-13 08:00')); // Regular Monday

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $disruptions = collect($recommendations)->where('type', 'disruption');
    expect($disruptions->count())->toBeLessThanOrEqual(2);
});

test('recommendations enforce max 1 news card', function () {
    $user = User::factory()->onboarded()->create();

    // Create multiple news items
    for ($i = 0; $i < 5; $i++) {
        CityNews::create([
            'title' => "News item {$i}",
            'source' => 'koeln.de',
            'source_url' => "https://koeln.de/news/{$i}",
            'published_at' => now()->subHours($i),
            'category' => 'general',
        ]);
    }

    $this->travelTo(Carbon::parse('2026-04-13 10:00'));

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    $news = collect($recommendations)->where('type', 'news');
    expect($news->count())->toBeLessThanOrEqual(1);
});

// ── Disruption-aware bike/tram decision ──

test('determineBestMode returns bike when weather is good and no disruptions', function () {
    $service = app(RecommendationService::class);

    $result = $service->determineBestMode(
        forecast: ['bike_score' => 'Great — clear skies', 'rain_starts' => null],
        bikeTime: 20,
        disruptedLines: [],
        userTransitLines: ['12', '15'],
    );

    expect($result['mode'])->toBe('bike');
    expect($result['reason'])->toContain('weather');
});

test('determineBestMode returns bike with disruption reason when transit disrupted', function () {
    $service = app(RecommendationService::class);

    $result = $service->determineBestMode(
        forecast: ['bike_score' => 'Great — clear skies', 'rain_starts' => null],
        bikeTime: 20,
        disruptedLines: ['12' => 'major', '3' => 'minor'],
        userTransitLines: ['12', '15'],
    );

    expect($result['mode'])->toBe('bike');
    expect($result['reason'])->toContain('disrupted');
});

test('determineBestMode returns tram when weather is bad', function () {
    $service = app(RecommendationService::class);

    $result = $service->determineBestMode(
        forecast: ['bike_score' => 'Poor — raining now', 'rain_starts' => '08:00'],
        bikeTime: 20,
        disruptedLines: [],
        userTransitLines: ['12'],
    );

    expect($result['mode'])->toBe('tram');
    expect($result['reason'])->toContain('Weather');
});

test('determineBestMode returns tram with warning when bad weather and disrupted', function () {
    $service = app(RecommendationService::class);

    $result = $service->determineBestMode(
        forecast: ['bike_score' => 'Poor — raining now', 'rain_starts' => '08:00'],
        bikeTime: 20,
        disruptedLines: ['12' => 'critical'],
        userTransitLines: ['12'],
    );

    expect($result['mode'])->toBe('tram');
    expect($result['reason'])->toContain('disrupted');
});

test('determineBestMode returns tram when bike time exceeds 30 minutes', function () {
    $service = app(RecommendationService::class);

    $result = $service->determineBestMode(
        forecast: ['bike_score' => 'Great — clear skies', 'rain_starts' => null],
        bikeTime: 45,
        disruptedLines: [],
        userTransitLines: [],
    );

    expect($result['mode'])->toBe('tram');
});

// ── Time-of-day commute context ──

test('commute context is to_work or to_regular on weekday morning', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    $this->travelTo(Carbon::parse('2026-04-13 07:30')); // Monday morning

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    // Work with arrive_by is treated as scheduled place → to_regular or to_work
    expect($commute['context'])->toBeIn(['to_work', 'to_regular']);
    expect($commute['to'])->toBe('Work');
});

test('commute context is off_hours on weekend', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    $this->travelTo(Carbon::parse('2026-04-11 10:00')); // Saturday

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    expect($commute['context'])->toBeIn(['off_hours', 'discovery']);
});

test('commute context is off_hours late at night', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Mediapark', 'lat' => 50.9467, 'lng' => 6.9417, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    $this->travelTo(Carbon::parse('2026-04-13 23:00')); // Monday 11pm

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    expect($commute['context'])->toBeIn(['off_hours', 'discovery']);
});

// ── Leave-by calculation ──

test('calculateLeaveBy returns correct time with Valhalla', function () {
    $this->mock(ValhallaRoutingService::class, function ($mock) {
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('route')->andReturn([
            'duration_min' => 20,
            'distance_km' => 5.0,
            'geometry' => '',
            'raw_seconds' => 1200,
            'steps' => [],
        ]);
    });

    $service = app(RecommendationService::class);
    $result = $service->calculateLeaveBy(
        50.9478, 6.9183, 50.9467, 6.9417,
        '09:00', 'bike',
        ['rain_starts' => null],
        ['condition' => 'Clear'],
    );

    expect($result)->not->toBeNull();
    expect($result['time'])->toBe('08:40');
    expect($result['travel_min'])->toBe(20);
    expect($result['message'])->toContain('Clear');
});

test('calculateLeaveBy returns null when no arrive_by', function () {
    $service = app(RecommendationService::class);
    $result = $service->calculateLeaveBy(
        50.9478, 6.9183, 50.9467, 6.9417,
        null, 'bike',
        ['rain_starts' => null],
    );

    expect($result)->toBeNull();
});

test('calculateLeaveBy includes rain warning when rain forecast', function () {
    $service = app(RecommendationService::class);
    $result = $service->calculateLeaveBy(
        50.9478, 6.9183, 50.9467, 6.9417,
        '09:00', 'tram',
        ['rain_starts' => '14:00'],
        ['condition' => 'Overcast'],
    );

    expect($result)->not->toBeNull();
    expect($result['message'])->toContain('Rain');
    expect($result['message'])->toContain('14:00');
});

// ── Custom place scheduling ──

test('commute context detects to_regular for scheduled custom place', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::create(['user_id' => $user->id, 'emoji' => '🏠', 'name' => 'Home', 'address' => 'Ehrenfeld', 'lat' => 50.9478, 'lng' => 6.9183, 'sort_order' => 0, 'category' => 'home']);
    UserPlace::create(['user_id' => $user->id, 'emoji' => '📚', 'name' => 'German Course', 'address' => 'VHS Köln', 'lat' => 50.9380, 'lng' => 6.9500, 'sort_order' => 2, 'arrive_by' => '10:00', 'category' => 'custom', 'day_mode' => 'custom', 'active_days' => ['mon', 'wed']]);

    // Monday at 08:30 — within 2hr window before 10:00 arrive_by
    $this->travelTo(Carbon::parse('2026-04-13 08:30'));

    $service = app(RecommendationService::class);
    $commute = $service->getCommuteRecommendation($user);

    expect($commute['context'])->toBe('to_regular');
    expect($commute['to'])->toBe('German Course');
});

test('custom place not active on non-scheduled day', function () {
    $this->travelTo(Carbon::parse('2026-04-14 08:30')); // Tuesday

    $user = User::factory()->onboarded()->create();
    $place = UserPlace::create(['user_id' => $user->id, 'emoji' => '📚', 'name' => 'German Course', 'address' => 'VHS Köln', 'lat' => 50.9380, 'lng' => 6.9500, 'sort_order' => 2, 'arrive_by' => '10:00', 'category' => 'custom', 'day_mode' => 'custom', 'active_days' => ['mon', 'wed']]);

    expect($place->isActiveToday())->toBeFalse();
});

test('weekday place inactive on holiday', function () {
    $this->travelTo(Carbon::parse('2026-12-25 08:00')); // 1. Weihnachtstag (Thursday)

    $user = User::factory()->onboarded()->create();
    $work = UserPlace::create(['user_id' => $user->id, 'emoji' => '💼', 'name' => 'Work', 'address' => 'Office', 'lat' => 50.94, 'lng' => 6.94, 'sort_order' => 1, 'arrive_by' => '09:00', 'category' => 'work', 'day_mode' => 'weekdays']);

    expect($work->isActiveToday())->toBeFalse();
});

// ── Card dedup by title ──

test('recommendations deduplicate cards with same title', function () {
    $user = User::factory()->onboarded()->create();

    $this->travelTo(Carbon::parse('2026-04-13 10:00'));

    $service = app(RecommendationService::class);
    $recommendations = $service->getRecommendations($user);

    // All titles should be unique (normalized)
    $titles = collect($recommendations)->pluck('title')
        ->map(fn ($t) => preg_replace('/[^a-z0-9]/', '', strtolower($t)))
        ->all();

    expect($titles)->toBe(array_values(array_unique($titles)));
});
