<?php

use App\Models\User;
use App\Models\UserPlace;
use App\Services\ValhallaRoutingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

test('sends notification within 15-min window before leave-by time', function () {
    $user = User::factory()->onboarded()->create();

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    $this->travelTo(Carbon::parse('2026-04-13 08:50'));

    // Clear stale Redis state for this user
    Redis::del("leaveby_debug:{$user->id}");
    Redis::del("notif_throttle:last:{$user->id}");
    Redis::del("notif_throttle:hour:{$user->id}:".now()->format('Y-m-d-H'));
    Redis::del("notif_throttle:day:{$user->id}:".now()->format('Y-m-d'));

    $this->artisan('commute:send-leaveby-reminders')->assertSuccessful();

    // Verify via Redis log that notification was calculated and sent
    $entries = Redis::zrevrange("leaveby_debug:{$user->id}", 0, 0);
    expect($entries)->not->toBeEmpty();

    $data = json_decode($entries[0], true);
    expect($data['place_name'])->toBe('Office');
    expect($data['skip_reason'])->toBeNull('Expected no skip reason but got: '.($data['skip_reason'] ?? 'null'));
    expect($data['notified'])->toBeTrue();
    expect($data['leave_by'])->not->toBeNull();
});

test('skips when outside notification window', function () {
    Notification::fake();

    User::factory()->onboarded()->create();
    $userId = User::first()->id;

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    $this->mock(ValhallaRoutingService::class, function ($mock) {
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('route')->andReturn([
            'duration_min' => 15,
            'distance_km' => 3.5,
            'geometry' => '',
            'raw_seconds' => 900,
            'steps' => [],
        ]);
    });

    $this->travelTo(now()->next('Monday')->setTime(7, 0));

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('0 leave-by reminder');
});

test('deduplicates same place same day', function () {
    User::factory()->onboarded()->create();
    $userId = User::first()->id;

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    $work = UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    $this->mock(ValhallaRoutingService::class, function ($mock) {
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('route')->andReturn([
            'duration_min' => 15,
            'distance_km' => 3.5,
            'geometry' => '',
            'raw_seconds' => 900,
            'steps' => [],
        ]);
    });

    $monday = now()->next('Monday')->setTime(8, 32);
    $this->travelTo($monday);

    Cache::put("leaveby_notif:{$userId}:{$work->id}:{$monday->format('Y-m-d')}", true, now()->endOfDay());

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('0 leave-by reminder');
});

test('respects user notification preferences', function () {
    $user = User::factory()->onboarded()->create();
    $user->notificationPreference()->create(['preferences' => ['transit' => false]]);

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    UserPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    $this->mock(ValhallaRoutingService::class, function ($mock) {
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('route')->andReturn([
            'duration_min' => 15,
            'distance_km' => 3.5,
            'geometry' => '',
            'raw_seconds' => 900,
            'steps' => [],
        ]);
    });

    $this->travelTo(now()->next('Monday')->setTime(8, 32));

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('0 leave-by reminder');
});

test('skips places inactive today based on day_mode', function () {
    User::factory()->onboarded()->create();
    $userId = User::first()->id;

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'German Course',
        'category' => 'custom',
        'arrive_by' => '10:00',
        'lat' => 50.9410,
        'lng' => 6.9550,
        'day_mode' => 'custom',
        'active_days' => ['fri'],
    ]);

    $this->mock(ValhallaRoutingService::class, function ($mock) {
        $mock->shouldReceive('isAvailable')->andReturn(true);
        $mock->shouldReceive('route')->andReturn([
            'duration_min' => 15,
            'distance_km' => 3.5,
            'geometry' => '',
            'raw_seconds' => 900,
            'steps' => [],
        ]);
    });

    $this->travelTo(now()->next('Monday')->setTime(9, 47));

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('0 leave-by reminder');
});

test('skips on public holidays', function () {
    User::factory()->onboarded()->create();
    $userId = User::first()->id;

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Home',
        'category' => 'home',
        'lat' => 50.9375,
        'lng' => 6.9603,
        'day_mode' => 'all',
    ]);

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    // Ostermontag 2026
    $this->travelTo(Carbon::parse('2026-04-06 08:32'));

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('Public holiday');
});

test('handles users without home place gracefully', function () {
    User::factory()->onboarded()->create();
    $userId = User::first()->id;

    UserPlace::factory()->create([
        'user_id' => $userId,
        'name' => 'Office',
        'category' => 'work',
        'arrive_by' => '09:00',
        'lat' => 50.9400,
        'lng' => 6.9650,
        'day_mode' => 'weekdays',
    ]);

    $this->travelTo(now()->next('Monday')->setTime(8, 32));

    Artisan::call('commute:send-leaveby-reminders');
    $output = Artisan::output();

    expect($output)->toContain('0 leave-by reminder');
});
