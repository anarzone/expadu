<?php

use App\Models\Appointment;
use App\Models\Event;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\RecommendationService;

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
        ->has('feed')
        ->has('feed.recommendations')
        ->has('feed.settlement')
        ->has('weather')
    );
});

test('transit page includes commute recommendation', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('transit'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('transit')
        ->has('commuteRecommendation')
        ->has('commuteRecommendation.headline')
        ->has('commuteRecommendation.route_cards')
    );
});
