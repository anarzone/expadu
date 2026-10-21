<?php

namespace App\Transit\MockSample;

use App\Services\WeatherService;
use App\Support\MockClock;

/**
 * The mock's sample weather (prototype/dev/design/events-next/sample-weather.js:
 * fictional review data, 21° and sun at 13:10, showers through the evening)
 * while the local mock clock is on, so tools/mock-compare sees the mock's
 * forecast wherever the app reads one: Plan a trip's checks, My day, Events'
 * sky, Today. It answers at the source (fetch), so every reader gets it
 * unchanged. The mock gives each hour a sky, a temperature and a chance of
 * rain; the sky becomes the providers' icon and the amounts, cloud cover and
 * visibility Events' street reads it by (the same as MockSample\EventsSeeder,
 * so the wall looks as it did). No wind or sun times. Every request without
 * the mock clock, and every other day, reads the real forecast. Bound only
 * in local (TravelServiceProvider).
 */
final class SampleWeather extends WeatherService
{
    /** Each hour from 00:00: [sky, °C, chance of rain in %], as the mock's file. */
    private const DAYS = [
        '2026-09-30' => [
            ['clear', 11, 0], ['clear', 11, 0], ['clear', 10, 0], ['clear', 10, 0],
            ['mist', 9, 0], ['mist', 9, 0], ['mist', 10, 0], ['partly', 11, 0],
            ['partly', 13, 0], ['partly', 15, 0], ['clear', 17, 0], ['clear', 19, 0],
            ['clear', 20, 0], ['clear', 21, 0], ['clear', 21, 0], ['partly', 21, 0],
            ['partly', 20, 10], ['cloudy', 19, 30], ['showers', 17, 60], ['showers', 16, 70],
            ['rain', 15, 70], ['cloudy', 14, 40], ['partly', 13, 10], ['clear', 12, 0],
        ],
    ];

    /** Each sky: the providers' icons (day, night), mm of rain, cloud cover %, visibility m. */
    private const SKIES = [
        'clear' => [['clear-day', 'clear-night'], 0.0, 10, 20000],
        'partly' => [['partly-cloudy-day', 'partly-cloudy-night'], 0.0, 50, 20000],
        'cloudy' => [['cloudy', 'cloudy'], 0.0, 90, 20000],
        'showers' => [['rain', 'rain'], 0.5, 85, 15000],
        'rain' => [['rain', 'rain'], 2.0, 95, 8000],
        'mist' => [['fog', 'fog'], 0.0, 40, 500],
    ];

    protected function fetch(float $lat, float $lng): array
    {
        $at = MockClock::at()?->setTimezone('Europe/Berlin');
        $hours = $at === null ? null : (self::DAYS[$at->format('Y-m-d')] ?? null);
        if ($hours === null) {
            return parent::fetch($lat, $lng);
        }

        $hourly = [];
        foreach ($hours as $hour => [$sky, $degrees, $rain]) {
            [$icons, $amount, $cloud, $visibility] = self::SKIES[$sky];
            $hourly[] = [
                'hour' => $hour,
                'time' => $at->setTime($hour, 0)->toIso8601String(),
                'precipitation' => $amount,
                'precipitation_probability' => $rain,
                'temperature' => (float) $degrees,
                'cloud_cover' => $cloud,
                'visibility' => $visibility,
                'icon' => $icons[$hour >= 7 && $hour < 19 ? 0 : 1],
            ];
        }
        $now = $hourly[$at->hour];

        return [
            'current' => [
                'temperature' => $now['temperature'],
                'feels_like' => null,
                'icon' => $now['icon'],
                'wind_speed' => 0,
                'wind_gust' => 0,
                'wind_direction' => 0,
                'humidity' => null,
                'precipitation' => $now['precipitation'],
            ],
            'hourly' => $hourly,
            'sun' => [],
        ];
    }
}
