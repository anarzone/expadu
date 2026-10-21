<?php

use App\Services\WeatherService;
use App\Transit\MockSample\SampleWeather;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

test('the mock sample weather is never bound outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(app(WeatherService::class))->not->toBeInstanceOf(SampleWeather::class);
});

test('without the local mock clock the sample reads the real forecast', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 13:10', 'Europe/Berlin'));
    Cache::put('weather_fresh:50.9375_6.9603', [
        'current' => ['temperature' => 4.0, 'feels_like' => null, 'icon' => 'cloudy', 'wind_speed' => 10, 'wind_gust' => 20, 'wind_direction' => 180, 'humidity' => 80, 'precipitation' => 0.0],
        'hourly' => [['hour' => 15, 'time' => '2026-09-30T15:00:00+02:00', 'precipitation' => 0.0, 'precipitation_probability' => 5, 'temperature' => 4.0, 'icon' => 'cloudy']],
    ], 60);

    // The sample's own day and moment: still the cached forecast, because
    // this isn't a local request with the mock clock.
    $day = (new SampleWeather([]))->getDayForecast('2026-09-30');

    expect($day['hours'])->toHaveCount(1)
        ->and($day['hours'][0]['temperature'])->toBe(4.0);
});
