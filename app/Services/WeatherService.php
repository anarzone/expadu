<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WeatherService
{
    /**
     * Get current weather using Open-Meteo API (DWD ICON model — same as Apple Weather in Germany).
     * Single API call returns both current conditions and hourly forecast.
     * Free, no API key, updates every 15 minutes.
     */
    public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
    {
        $data = $this->fetchOpenMeteo($lat, $lng);

        $current = $data['current'] ?? [];
        $weatherCode = $current['weather_code'] ?? 3;

        return [
            'temperature' => (int) floor($current['temperature_2m'] ?? 0),
            'feels_like' => (int) floor($current['apparent_temperature'] ?? $current['temperature_2m'] ?? 0),
            'icon' => $this->wmoToIcon($weatherCode),
            'emoji' => $this->wmoToEmoji($weatherCode),
            'condition' => $this->wmoToCondition($weatherCode),
            'wind_speed' => (int) floor($current['wind_speed_10m'] ?? 0),
            'wind_gust' => (int) floor($current['wind_gusts_10m'] ?? 0),
            'wind_direction' => $current['wind_direction_10m'] ?? 0,
            'humidity' => round($current['relative_humidity_2m'] ?? 0),
            'precipitation' => $current['precipitation'] ?? 0,
        ];
    }

    /**
     * Get hourly forecast for today. Returns rain start time and bike score.
     */
    public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
    {
        $data = $this->fetchOpenMeteo($lat, $lng);

        $hourly = $data['hourly'] ?? [];
        $times = $hourly['time'] ?? [];
        $precip = $hourly['precipitation'] ?? [];
        $nowHour = now()->hour;
        $rainStart = null;

        foreach ($times as $i => $time) {
            $hour = (int) date('G', strtotime($time));
            if ($hour <= $nowHour) {
                continue;
            }
            if (($precip[$i] ?? 0) > 0.1 && ! $rainStart) {
                $rainStart = str_pad($hour, 2, '0', STR_PAD_LEFT).':00';
            }
        }

        $current = $this->getCurrentWeather($lat, $lng);
        $bikeScore = $this->calculateBikeScore($current, $rainStart);

        return [
            'rain_starts' => $rainStart,
            'bike_score' => $bikeScore,
            'hourly' => [],
        ];
    }

    /**
     * Fetch Open-Meteo data — cached for 5 minutes.
     * Single call returns current + hourly forecast.
     */
    protected function fetchOpenMeteo(float $lat, float $lng): array
    {
        return Cache::remember("openmeteo_{$lat}_{$lng}", 300, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(5)->retry(2, 500)
                    ->get('https://api.open-meteo.com/v1/dwd-icon', [
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m',
                        'hourly' => 'precipitation,temperature_2m,wind_speed_10m',
                        'timezone' => 'Europe/Berlin',
                        'forecast_days' => 1,
                    ]);

                if ($response->successful()) {
                    return $response->json();
                }
            } catch (\Exception $e) {
                report($e);
            }

            return [];
        });
    }

    protected function calculateBikeScore(array $weather, ?string $rainStart): string
    {
        $temp = $weather['temperature'];
        $wind = $weather['wind_speed'];

        if ($temp < 0) {
            return 'Poor — freezing';
        }
        if ($wind > 40) {
            return 'Poor — strong wind';
        }
        if ($rainStart && $rainStart <= date('H:i')) {
            return 'Poor — raining now';
        }
        if ($rainStart) {
            return "Good until {$rainStart}";
        }
        if ($wind > 25) {
            return 'OK — windy';
        }

        return 'Great — clear skies';
    }

    /**
     * WMO Weather Code to emoji.
     * https://www.nodc.noaa.gov/archive/arc0021/0002199/1.1/data/0-data/HTML/WMO-CODE/WMO4677.HTM
     */
    protected function wmoToEmoji(int $code): string
    {
        return match (true) {
            $code === 0 => '☀️',
            $code <= 3 => '⛅',
            $code <= 49 => '🌫️',
            $code <= 59 => '🌦️',
            $code <= 69 => '🌧️',
            $code <= 79 => '🌨️',
            $code <= 84 => '🌧️',
            $code <= 89 => '🌨️',
            $code <= 99 => '⛈️',
            default => '⛅',
        };
    }

    protected function wmoToIcon(int $code): string
    {
        return match (true) {
            $code === 0 => now()->hour >= 6 && now()->hour < 20 ? 'clear-day' : 'clear-night',
            $code <= 2 => 'partly-cloudy-day',
            $code === 3 => 'cloudy',
            $code <= 49 => 'fog',
            $code <= 69 => 'rain',
            $code <= 79 => 'snow',
            $code <= 84 => 'rain',
            $code <= 89 => 'hail',
            $code <= 99 => 'thunderstorm',
            default => 'cloudy',
        };
    }

    protected function wmoToCondition(int $code): string
    {
        return match (true) {
            $code === 0 => 'Clear sky',
            $code === 1 => 'Mainly clear',
            $code === 2 => 'Partly cloudy',
            $code === 3 => 'Overcast',
            $code <= 49 => 'Foggy',
            $code <= 55 => 'Drizzle',
            $code <= 59 => 'Freezing drizzle',
            $code <= 65 => 'Rain',
            $code <= 69 => 'Freezing rain',
            $code <= 75 => 'Snow',
            $code === 77 => 'Snow grains',
            $code <= 82 => 'Rain showers',
            $code <= 86 => 'Snow showers',
            $code <= 89 => 'Hail',
            $code <= 99 => 'Thunderstorm',
            default => 'Partly cloudy',
        };
    }
}
