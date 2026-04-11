<?php

namespace App\Services;

use App\Services\Weather\BrightSkyProvider;
use App\Services\Weather\MetNoProvider;
use App\Services\Weather\OpenMeteoProvider;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WttrInProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WeatherService
{
    /**
     * Ordered list of providers. First successful response wins.
     *
     * @var list<WeatherProvider>
     */
    protected array $providers;

    /**
     * @param  list<WeatherProvider>|null  $providers
     */
    public function __construct(?array $providers = null)
    {
        // Ordered by reliability first, data quality second:
        //  1. wttr.in     — most reliable in our tests, real FeelsLikeC, gust from current hour
        //  2. Open-Meteo  — best data when up (sun-aware AT) but recently degraded (502/429/timeouts)
        //  3. Bright Sky  — DWD station observations, no feels_like
        //  4. Met.no      — Norwegian Met Institute, no feels_like
        $this->providers = $providers ?? [
            app(WttrInProvider::class),
            app(OpenMeteoProvider::class),
            app(BrightSkyProvider::class),
            app(MetNoProvider::class),
        ];
    }

    /**
     * Get current weather. Uses DWD (Bright Sky) with Met.no fallback.
     */
    public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
    {
        $data = $this->fetch($lat, $lng);
        $current = $data['current'] ?? null;

        if (! $current) {
            return $this->unavailableWeather();
        }

        $icon = $current['icon'];

        return [
            'temperature' => (int) floor($current['temperature']),
            'feels_like' => $current['feels_like'] !== null
                ? (int) floor($current['feels_like'])
                : null,
            'icon' => $icon,
            'emoji' => $this->iconToEmoji($icon),
            'condition' => $this->iconToCondition($icon),
            'wind_speed' => (int) floor($current['wind_speed']),
            'wind_gust' => (int) floor($current['wind_gust']),
            'wind_direction' => (int) floor($current['wind_direction']),
            'humidity' => $current['humidity'] !== null ? (int) round($current['humidity']) : 0,
            'precipitation' => (float) $current['precipitation'],
        ];
    }

    /**
     * Get hourly forecast — returns rain start time and a bike score.
     */
    public function getForecast(float $lat = 50.9375, float $lng = 6.9603): array
    {
        $data = $this->fetch($lat, $lng);
        $hourly = $data['hourly'] ?? [];
        $nowHour = now('Europe/Berlin')->hour;
        $rainStart = null;

        foreach ($hourly as $entry) {
            if ($entry['hour'] <= $nowHour) {
                continue;
            }
            if ($entry['precipitation'] > 0.1) {
                $rainStart = str_pad((string) $entry['hour'], 2, '0', STR_PAD_LEFT).':00';
                break;
            }
        }

        $current = $this->getCurrentWeather($lat, $lng);
        $isRainingNow = ($current['precipitation'] ?? 0) > 0
            || in_array($current['icon'] ?? '', ['rain', 'sleet', 'thunderstorm', 'hail']);
        $bikeScore = $this->calculateBikeScore($current, $rainStart, $isRainingNow);

        // Build next-hours forecast (current hour + next 7 = 8 slots)
        $nextHours = [];
        foreach ($hourly as $entry) {
            if ($entry['hour'] < $nowHour) {
                continue;
            }
            $nextHours[] = [
                'hour' => str_pad((string) $entry['hour'], 2, '0', STR_PAD_LEFT).':00',
                'precip' => round((float) $entry['precipitation'], 1),
            ];
            if (count($nextHours) >= 8) {
                break;
            }
        }

        // Smart rain summary from hourly data
        $rainSummary = $this->buildRainSummary($nextHours, $isRainingNow);

        return [
            'rain_starts' => $isRainingNow && ! $rainStart ? 'now' : $rainStart,
            'bike_score' => $bikeScore,
            'rain_summary' => $rainSummary,
            'hourly' => $nextHours,
        ];
    }

    /**
     * Try each provider in order. Cache only successful responses.
     *
     * @return array{current: array<string, mixed>, hourly: list<array{hour: int, precipitation: float}>}|array{}
     */
    protected function fetch(float $lat, float $lng): array
    {
        $cacheKey = "weather_{$lat}_{$lng}";
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && ! empty($cached)) {
            return $cached;
        }

        foreach ($this->providers as $provider) {
            $data = $provider->fetch($lat, $lng);

            if ($data !== null) {
                Cache::put($cacheKey, $data, 300);

                return $data;
            }

            Log::warning("Weather provider {$provider->name()} returned null, falling back");
        }

        Log::error("All weather providers failed for ({$lat}, {$lng})");

        return [];
    }

    /**
     * Placeholder values when every provider fails. The `condition` is the
     * signal consumers can use to show a "weather unavailable" state.
     */
    protected function unavailableWeather(): array
    {
        return [
            'temperature' => 0,
            'feels_like' => null,
            'icon' => 'cloudy',
            'emoji' => '☁️',
            'condition' => 'Unavailable',
            'wind_speed' => 0,
            'wind_gust' => 0,
            'wind_direction' => 0,
            'humidity' => 0,
            'precipitation' => 0.0,
        ];
    }

    /**
     * Generate a human-readable rain summary from hourly forecast data.
     *
     * @param  array<int, array{hour: string, precip: float}>  $hours
     */
    protected function buildRainSummary(array $hours, bool $isRainingNow): string
    {
        if (empty($hours)) {
            return $isRainingNow ? 'Raining now' : 'No data';
        }

        $rainyHours = array_filter($hours, fn ($h) => $h['precip'] > 0.1);
        $dryHours = array_filter($hours, fn ($h) => $h['precip'] <= 0.1);

        // All dry
        if (empty($rainyHours)) {
            return $isRainingNow ? 'Clearing soon' : 'Dry next '.count($hours).' hours';
        }

        // All rainy
        if (empty($dryHours)) {
            return 'Rain all day';
        }

        // Find first rain and first clear after rain
        $firstRain = null;
        $clearsAt = null;

        foreach ($hours as $h) {
            if ($h['precip'] > 0.1 && ! $firstRain) {
                $firstRain = $h['hour'];
            }
            if ($firstRain && $h['precip'] <= 0.1 && ! $clearsAt) {
                $clearsAt = $h['hour'];
            }
        }

        if ($isRainingNow && $clearsAt) {
            return "Rain until {$clearsAt}";
        }

        if ($isRainingNow) {
            return 'Rain continuing';
        }

        if ($firstRain) {
            $duration = count($rainyHours);
            if ($clearsAt) {
                return "Rain {$firstRain}–{$clearsAt}";
            }

            return "Rain from {$firstRain} ({$duration}h+)";
        }

        return 'Dry next '.count($hours).' hours';
    }

    protected function calculateBikeScore(array $weather, ?string $rainStart, bool $isRainingNow = false): string
    {
        $temp = $weather['temperature'];
        $wind = $weather['wind_speed'];

        if ($temp < 0) {
            return 'Poor — freezing';
        }
        if ($wind > 40) {
            return 'Poor — strong wind';
        }
        if ($isRainingNow) {
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
        $isNight = now()->hour < 6 || now()->hour >= 20;

        return match ($icon) {
            'clear-day' => '☀️',
            'clear-night' => '🌙',
            'partly-cloudy-day' => '⛅',
            'partly-cloudy-night' => '🌙',
            'cloudy' => '☁️',
            'fog' => '🌫️',
            'wind' => '💨',
            'rain' => '🌧️',
            'sleet' => '🌨️',
            'snow' => '🌨️',
            'hail' => '🌧️',
            'thunderstorm' => '⛈️',
            default => $isNight ? '🌙' : '⛅',
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
}
