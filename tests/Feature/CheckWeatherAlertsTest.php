<?php

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\WeatherAlertNotification;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Notification::fake();
    Cache::flush();

    // 8 AM is within the commute window (6-9) required by NotificationThrottle for weather_commute
    $this->travelTo(now()->setTime(8, 0));

    // Reset throttle counters for any user that will be created in this test
    // Use specific key deletion instead of flushdb to avoid parallel test interference
    try {
        $today = now()->format('Y-m-d');
        $hour = now()->format('Y-m-d-H');
        // Clear for user IDs 1-20 (covers auto-increment in test DB)
        for ($i = 1; $i <= 20; $i++) {
            Redis::del("notif_throttle:last:{$i}");
            Redis::del("notif_throttle:hour:{$i}:{$hour}");
            Redis::del("notif_throttle:day:{$i}:{$today}");
        }
    } catch (Throwable) {
    }
});

function weatherMock(array $currentOverrides = [], ?string $rainStarts = null): array
{
    $current = array_merge([
        'temperature' => 15,
        'feels_like' => 13,
        'icon' => 'clear-day',
        'emoji' => '☀️',
        'condition' => 'Clear sky',
        'wind_speed' => 10,
        'wind_gust' => 15,
        'wind_direction' => 180,
        'humidity' => 50,
        'precipitation' => 0.0,
    ], $currentOverrides);

    $forecast = [
        'rain_starts' => $rainStarts,
        'bike_score' => 80,
    ];

    return [$current, $forecast];
}

// --- Wind alert tests (Bug #1: wind_gusts vs wind_gust) ---

test('fires wind alert when wind_gust exceeds 60 kmh', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock(['wind_gust' => 75]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertSentTo($user, WeatherAlertNotification::class);
});

test('does not fire wind alert when wind_gust is below threshold', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock(['wind_gust' => 40]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertNotSentTo($user, WeatherAlertNotification::class);
});

// --- Other weather conditions ---

test('fires rain alert when forecast has rain_starts', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock([], '14:00');
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertSentTo($user, WeatherAlertNotification::class);
});

test('fires freezing alert when temperature below zero', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock(['temperature' => -5]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertSentTo($user, WeatherAlertNotification::class);
});

test('fires heat alert when temperature above 33', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock(['temperature' => 36]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertSentTo($user, WeatherAlertNotification::class);
});

test('does not fire alert when weather is normal', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock();
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertNotSentTo($user, WeatherAlertNotification::class);
});

// --- Preference check (Bug #2: events vs weather) ---

test('respects weather notification preference opt-out', function () {
    $user = User::factory()->onboarded()->create();
    NotificationPreference::create([
        'user_id' => $user->id,
        'preferences' => array_merge(NotificationPreference::defaults(), ['weather' => false]),
    ]);
    [$current, $forecast] = weatherMock(['wind_gust' => 75]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertNotSentTo($user, WeatherAlertNotification::class);
});

// --- Dedup ---

test('deduplicates same alert within 12 hours', function () {
    $user = User::factory()->onboarded()->create();
    [$current, $forecast] = weatherMock([], '14:00');
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();
    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertSentToTimes($user, WeatherAlertNotification::class, 1);
});

// --- Guards ---

test('skips non-onboarded users', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    [$current, $forecast] = weatherMock(['wind_gust' => 75]);
    $this->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Notification::assertNotSentTo($user, WeatherAlertNotification::class);
});
