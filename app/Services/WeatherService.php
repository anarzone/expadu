<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class WeatherService
{
    /**
     * Get current weather for Cologne (default coordinates).
     *
     * @return array{temperature: float, icon: string, emoji: string, condition: string, wind_speed: float, wind_direction: int, humidity: float, precipitation: float}
     */
    public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
    {
        return Cache::remember("weather_current_{$lat}_{$lng}", 300, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(5)->retry(2, 500)
                    ->get('https://api.brightsky.dev/current_weather', [
                        'lat' => $lat,
                        'lon' => $lng,
                    ]);

                if ($response->successful()) {
                    $w = $response->json('weather');

                    // Also fetch hourly forecast for accurate wind speed
                    // (current_weather only has 10-min measurement averages which are too high)
                    $hourlyWind = null;
                    $hourlyGust = null;
                    try {
                        $today = now()->format('Y-m-d');
                        $hourlyRes = Http::timeout(3)->get('https://api.brightsky.dev/weather', [
                            'lat' => $lat, 'lon' => $lng, 'date' => $today, 'last_date' => $today,
                        ]);
                        if ($hourlyRes->successful()) {
                            $hours = $hourlyRes->json('weather', []);
                            // Find closest hour to now
                            $nowHour = now()->hour;
                            $currentHour = null;
                            foreach ($hours as $h) {
                                $hHour = (int) date('G', strtotime($h['timestamp']));
                                if ($hHour <= $nowHour) {
                                    $currentHour = $h; // keep updating to get the latest before now
                                }
                            }
                            $currentHour = $currentHour ?? end($hours) ?: null;
                            if ($currentHour) {
                                $hourlyWind = $currentHour['wind_speed'] ?? null;
                                $hourlyGust = $currentHour['wind_gust_speed'] ?? null;
                            }
                        }
                    } catch (\Exception) {
                        // Ignore — fall back to current_weather values
                    }

                    return [
                        'temperature' => round($w['temperature'] ?? 0),
                        'icon' => $w['icon'] ?? 'cloudy',
                        'emoji' => $this->iconToEmoji($w['icon'] ?? 'cloudy'),
                        'condition' => $this->iconToCondition($w['icon'] ?? 'cloudy'),
                        'wind_speed' => round($hourlyWind ?? $w['wind_speed_60'] ?? $w['wind_speed_10'] ?? 0),
                        'wind_gust' => round($hourlyGust ?? $w['wind_gust_speed_10'] ?? 0),
                        'wind_direction' => $w['wind_direction_10'] ?? 0,
                        'humidity' => round($w['relative_humidity'] ?? 0),
                        'precipitation' => $w['precipitation_10'] ?? 0,
                    ];
                }
            } catch (\Exception $e) {
                report($e);
            }

            return $this->fallbackWeather();
        });
    }

    /**
     * Get hourly forecast for today. Returns rain start time and bike score.
     *
     * @return array{rain_starts: string|null, bike_score: string, hourly: array}
     */
    public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
    {
        return Cache::remember("weather_forecast_{$lat}_{$lng}", 300, function () use ($lat, $lng) {
            try {
                $today = now()->format('Y-m-d');
                $response = Http::timeout(5)->retry(2, 500)
                    ->get('https://api.brightsky.dev/weather', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'date' => $today,
                        'last_date' => $today,
                    ]);

                if ($response->successful()) {
                    $hours = $response->json('weather', []);
                    $rainStart = null;
                    $now = now()->hour;

                    foreach ($hours as $h) {
                        $hour = (int) date('G', strtotime($h['timestamp']));
                        if ($hour <= $now) {
                            continue;
                        }
                        if (($h['precipitation'] ?? 0) > 0 && ! $rainStart) {
                            $rainStart = str_pad($hour, 2, '0', STR_PAD_LEFT).':00';
                        }
                    }

                    $current = $this->getCurrentWeather($lat, $lng);
                    $bikeScore = $this->calculateBikeScore($current, $rainStart);

                    return [
                        'rain_starts' => $rainStart,
                        'bike_score' => $bikeScore,
                        'hourly' => array_slice($hours, $now, 12),
                    ];
                }
            } catch (\Exception $e) {
                report($e);
            }

            return [
                'rain_starts' => '18:00',
                'bike_score' => 'Good until 17:45',
                'hourly' => [],
            ];
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

    protected function iconToEmoji(string $icon): string
    {
        return match ($icon) {
            'clear-day' => '☀️',
            'clear-night' => '🌙',
            'partly-cloudy-day' => '⛅',
            'partly-cloudy-night' => '☁️',
            'cloudy' => '☁️',
            'fog' => '🌫️',
            'wind' => '🌬️',
            'rain' => '🌧️',
            'sleet' => '🌨️',
            'snow' => '❄️',
            'hail' => '🌨️',
            'thunderstorm' => '⛈️',
            default => '⛅',
        };
    }

    protected function iconToCondition(string $icon): string
    {
        return match ($icon) {
            'clear-day', 'clear-night' => 'Clear sky',
            'partly-cloudy-day', 'partly-cloudy-night' => 'Partly cloudy',
            'cloudy' => 'Overcast',
            'fog' => 'Foggy',
            'wind' => 'Windy',
            'rain' => 'Rain',
            'sleet' => 'Sleet',
            'snow' => 'Snow',
            'hail' => 'Hail',
            'thunderstorm' => 'Thunderstorm',
            default => 'Partly cloudy',
        };
    }

    /**
     * @return array{temperature: float, icon: string, emoji: string, condition: string, wind_speed: float, wind_direction: int, humidity: float, precipitation: float}
     */
    protected function fallbackWeather(): array
    {
        return [
            'temperature' => 0,
            'icon' => 'cloudy',
            'emoji' => '☁️',
            'condition' => 'Weather unavailable',
            'wind_speed' => 0,
            'wind_gust' => 0,
            'wind_direction' => 0,
            'humidity' => 0,
            'precipitation' => 0,
        ];
    }
}
