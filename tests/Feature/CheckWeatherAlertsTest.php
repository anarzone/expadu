<?php

use App\Events\Context\WeatherChanged;
use App\Services\WeatherService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Cache::flush();
    Event::fake([WeatherChanged::class]);

    // Travel to a unique date per test to avoid dedup key collisions in Redis;
    // morning hour keeps the market-closure branch (12:00–16:00) out of the way.
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(8, 0));
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

function mockWeather(array $current, array $forecast): void
{
    test()->mock(WeatherService::class, function ($mock) use ($current, $forecast) {
        $mock->shouldReceive('getCurrentWeather')->andReturn($current);
        $mock->shouldReceive('getForecast')->andReturn($forecast);
    });
}

// Detection rules — per-user matching and push delivery live in
// WeatherEvaluator + ActionBus and are covered by the ContextEngine tests.

test('emits weather event when wind_gust exceeds 60 kmh', function () {
    [$current, $forecast] = weatherMock(['wind_gust' => 75]);
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertDispatched(WeatherChanged::class, function ($event) {
        return collect($event->alerts)->contains(
            fn ($alert) => str_contains($alert['title'], 'wind')
        );
    });
});

test('does not emit wind alert when wind_gust is below threshold', function () {
    [$current, $forecast] = weatherMock(['wind_gust' => 40]);
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertNotDispatched(WeatherChanged::class);
});

test('emits rain alert when forecast has rain_starts', function () {
    [$current, $forecast] = weatherMock([], '14:00');
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertDispatched(WeatherChanged::class, function ($event) {
        return collect($event->alerts)->contains(
            fn ($alert) => str_contains($alert['title'], 'Rain')
        );
    });
});

test('emits freezing alert when temperature below zero', function () {
    [$current, $forecast] = weatherMock(['temperature' => -5]);
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertDispatched(WeatherChanged::class, function ($event) {
        return collect($event->alerts)->contains(
            fn ($alert) => str_contains($alert['title'], 'Freezing')
        );
    });
});

test('emits heat alert when temperature above 33', function () {
    [$current, $forecast] = weatherMock(['temperature' => 36]);
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertDispatched(WeatherChanged::class, function ($event) {
        return collect($event->alerts)->contains(
            fn ($alert) => str_contains($alert['title'], 'Heat')
        );
    });
});

test('emits nothing when weather is normal', function () {
    [$current, $forecast] = weatherMock();
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertNotDispatched(WeatherChanged::class);
});

test('deduplicates same alert within 12 hours', function () {
    [$current, $forecast] = weatherMock([], '14:00');
    mockWeather($current, $forecast);

    $this->artisan('weather:check-alerts')->assertSuccessful();
    $this->artisan('weather:check-alerts')->assertSuccessful();

    Event::assertDispatchedTimes(WeatherChanged::class, 1);
});
