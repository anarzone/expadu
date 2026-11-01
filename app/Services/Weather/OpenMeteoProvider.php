<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Open-Meteo — free, no API key, unlimited, uses DWD ICON model for Germany.
 *
 * Provides real apparent_temperature (Steadman AT with solar radiation) — the
 * only free provider in our chain that returns a model-computed "feels like"
 * that accounts for sun exposure. Preferred primary for this reason.
 *
 * Docs: https://open-meteo.com/en/docs
 */
class OpenMeteoProvider implements WeatherProvider
{
    public function name(): string
    {
        return 'openmeteo';
    }

    public function fetch(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(2)
                ->connectTimeout(1)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,cloud_cover',
                    'hourly' => 'precipitation,precipitation_probability,temperature_2m,cloud_cover,visibility,weather_code,is_day',
                    'daily' => 'sunrise,sunset',
                    'timezone' => 'Europe/Berlin',
                    // Open-Meteo's local times keep one UTC offset for the whole
                    // response, so a day that changes to winter time (25 Oct
                    // 2026) would be an hour out. Instants are converted here.
                    'timeformat' => 'unixtime',
                    // A week ahead: Events reads the sky for the day it shows.
                    'forecast_days' => 7,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $current = $data['current'] ?? null;

            if (! is_array($current) || empty($current)) {
                return null;
            }

            // Override weather code when model disagrees with actual precipitation
            $weatherCode = (int) ($current['weather_code'] ?? 3);
            $precip = (float) ($current['precipitation'] ?? 0);
            $cloudCover = (int) ($current['cloud_cover'] ?? 50);

            if ($precip < 0.5 && $weatherCode >= 50 && $weatherCode <= 69) {
                $weatherCode = match (true) {
                    $cloudCover <= 10 => 0,
                    $cloudCover <= 50 => 2,
                    default => 3,
                };
            }

            return [
                'current' => [
                    'temperature' => (float) ($current['temperature_2m'] ?? 0),
                    'feels_like' => isset($current['apparent_temperature'])
                        ? (float) $current['apparent_temperature']
                        : null,
                    'icon' => $this->wmoToIcon($weatherCode),
                    'wind_speed' => (float) ($current['wind_speed_10m'] ?? 0),
                    'wind_gust' => (float) ($current['wind_gusts_10m'] ?? 0),
                    'wind_direction' => (float) ($current['wind_direction_10m'] ?? 0),
                    'humidity' => isset($current['relative_humidity_2m'])
                        ? (float) $current['relative_humidity_2m']
                        : null,
                    'precipitation' => $precip,
                ],
                'hourly' => $this->buildHourly($data['hourly'] ?? []),
                'sun' => $this->buildSun($data['daily'] ?? []),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * One entry per hour, at its real instant in Europe/Berlin. Precipitation
     * is the hour before the time, as Open-Meteo sums it.
     *
     * @return list<array<string, mixed>>
     */
    private function buildHourly(array $hourly): array
    {
        $result = [];

        foreach ($hourly['time'] ?? [] as $i => $time) {
            if (! is_numeric($time)) {
                continue;
            }
            $local = CarbonImmutable::createFromTimestamp((int) $time, 'Europe/Berlin');
            $code = $hourly['weather_code'][$i] ?? null;
            $result[] = [
                'hour' => $local->hour,
                'time' => $local->toIso8601String(),
                'precipitation' => (float) ($hourly['precipitation'][$i] ?? 0),
                'precipitation_probability' => $this->number($hourly['precipitation_probability'][$i] ?? null, true),
                'temperature' => $this->number($hourly['temperature_2m'][$i] ?? null),
                'cloud_cover' => $this->number($hourly['cloud_cover'][$i] ?? null, true),
                'visibility' => $this->number($hourly['visibility'][$i] ?? null, true),
                'icon' => is_numeric($code) ? $this->wmoToIcon((int) $code, isset($hourly['is_day'][$i]) ? (bool) $hourly['is_day'][$i] : null) : null,
            ];
        }

        return $result;
    }

    /**
     * Sunrise and sunset by local date.
     *
     * @return array<string, array{sunrise: string|null, sunset: string|null}>
     */
    private function buildSun(array $daily): array
    {
        $sun = [];
        foreach (['sunrise', 'sunset'] as $key) {
            foreach ($daily[$key] ?? [] as $time) {
                if (! is_numeric($time)) {
                    continue;
                }
                $local = CarbonImmutable::createFromTimestamp((int) $time, 'Europe/Berlin');
                $sun[$local->toDateString()] ??= ['sunrise' => null, 'sunset' => null];
                $sun[$local->toDateString()][$key] = $local->toIso8601String();
            }
        }

        return $sun;
    }

    private function number(mixed $value, bool $whole = false): int|float|null
    {
        if (! is_numeric($value)) {
            return null;
        }

        return $whole ? (int) round((float) $value) : round((float) $value, 1);
    }

    /**
     * Map WMO weather codes to our internal icon vocabulary.
     *
     * Reference: https://www.nodc.noaa.gov/archive/arc0021/0002199/1.1/data/0-data/HTML/WMO-CODE/WMO4677.HTM
     */
    private function wmoToIcon(int $code, ?bool $isDay = null): string
    {
        $isDay ??= now('Europe/Berlin')->hour >= 6 && now('Europe/Berlin')->hour < 20;

        return match (true) {
            $code === 0 => $isDay ? 'clear-day' : 'clear-night',
            $code <= 2 => $isDay ? 'partly-cloudy-day' : 'partly-cloudy-night',
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
}
