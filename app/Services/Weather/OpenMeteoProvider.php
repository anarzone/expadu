<?php

namespace App\Services\Weather;

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
            $response = Http::timeout(5)
                ->retry(2, 500, throw: false)
                ->get('https://api.open-meteo.com/v1/forecast', [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,precipitation,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,cloud_cover',
                    'hourly' => 'precipitation',
                    'timezone' => 'Europe/Berlin',
                    'forecast_days' => 2,
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
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{hour: int, precipitation: float}>
     */
    private function buildHourly(array $hourly): array
    {
        $times = $hourly['time'] ?? [];
        $precip = $hourly['precipitation'] ?? [];
        $result = [];

        foreach ($times as $i => $time) {
            $hour = (int) date('G', strtotime((string) $time));
            $result[] = [
                'hour' => $hour,
                'precipitation' => (float) ($precip[$i] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Map WMO weather codes to our internal icon vocabulary.
     *
     * Reference: https://www.nodc.noaa.gov/archive/arc0021/0002199/1.1/data/0-data/HTML/WMO-CODE/WMO4677.HTM
     */
    private function wmoToIcon(int $code): string
    {
        $isDay = now('Europe/Berlin')->hour >= 6 && now('Europe/Berlin')->hour < 20;

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
