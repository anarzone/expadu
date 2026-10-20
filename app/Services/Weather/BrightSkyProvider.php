<?php

namespace App\Services\Weather;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bright Sky wraps the German DWD weather service. Free, no key, unlimited.
 *
 * Docs: https://brightsky.dev/docs/
 *
 * Calls two endpoints:
 * - /current_weather  → station-observed current conditions (includes humidity)
 * - /weather          → hourly model forecast for today (precipitation per hour)
 *
 * Station observations are used for "current" where available because they are
 * real measurements rather than interpolated model output.
 */
class BrightSkyProvider implements WeatherProvider
{
    public function name(): string
    {
        return 'brightsky';
    }

    public function fetch(float $lat, float $lng): ?array
    {
        try {
            $hourly = $this->fetchHourly($lat, $lng);

            if ($hourly === null) {
                return null;
            }

            $current = $this->fetchCurrent($lat, $lng) ?? $hourly['current_from_hourly'];

            return [
                'current' => $current,
                'hourly' => $hourly['hourly'],
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Fetch station-observed current weather. Includes humidity and wind gust.
     *
     * @return array<string, mixed>|null
     */
    private function fetchCurrent(float $lat, float $lng): ?array
    {
        $response = Http::timeout(2)
            ->connectTimeout(1)
            ->get('https://api.brightsky.dev/current_weather', [
                'lat' => $lat,
                'lon' => $lng,
                'units' => 'dwd',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $weather = $response->json('weather');

        if (! is_array($weather)) {
            return null;
        }

        // Bright Sky's current_weather returns wind/gust in 10-minute windows
        // (wind_speed_10, wind_gust_speed_10). Prefer 10 min, fall back to 30/60.
        $windSpeed = $weather['wind_speed_10']
            ?? $weather['wind_speed_30']
            ?? $weather['wind_speed_60']
            ?? 0;
        $windGust = $weather['wind_gust_speed_10']
            ?? $weather['wind_gust_speed_30']
            ?? $weather['wind_gust_speed_60']
            ?? 0;
        $windDirection = $weather['wind_direction_10']
            ?? $weather['wind_direction_30']
            ?? $weather['wind_direction_60']
            ?? 0;

        return [
            'temperature' => (float) ($weather['temperature'] ?? 0),
            'feels_like' => null,
            'icon' => (string) ($weather['icon'] ?? 'cloudy'),
            'wind_speed' => (float) $windSpeed,
            'wind_gust' => (float) $windGust,
            'wind_direction' => (float) $windDirection,
            'humidity' => isset($weather['relative_humidity']) && $weather['relative_humidity'] !== null
                ? (float) $weather['relative_humidity']
                : null,
            'precipitation' => (float) ($weather['precipitation_60']
                ?? $weather['precipitation_30']
                ?? $weather['precipitation_10']
                ?? 0),
        ];
    }

    /**
     * Fetch hourly forecast for today plus build a "current" fallback from the
     * entry matching the current hour (used if /current_weather fails).
     *
     * @return array{hourly: list<array{hour: int, precipitation: float}>, current_from_hourly: array<string, mixed>}|null
     */
    private function fetchHourly(float $lat, float $lng): ?array
    {
        $response = Http::timeout(2)
            ->connectTimeout(1)
            ->get('https://api.brightsky.dev/weather', [
                'lat' => $lat,
                'lon' => $lng,
                'date' => now('Europe/Berlin')->toDateString(),
                'last_date' => now('Europe/Berlin')->addDay()->toDateString(),
                'units' => 'dwd',
            ]);

        if (! $response->successful()) {
            return null;
        }

        $entries = $response->json('weather') ?? [];

        if (empty($entries)) {
            return null;
        }

        $currentHour = now('Europe/Berlin')->hour;
        $currentEntry = null;

        foreach ($entries as $entry) {
            if ($this->hourEntry($entry)['hour'] === $currentHour) {
                $currentEntry = $entry;
                break;
            }
        }

        $currentEntry ??= $entries[0];

        return [
            'hourly' => array_values(array_map(fn (array $entry) => $this->hourEntry($entry), $entries)),
            'current_from_hourly' => [
                'temperature' => (float) ($currentEntry['temperature'] ?? 0),
                'feels_like' => null,
                'icon' => (string) ($currentEntry['icon'] ?? 'cloudy'),
                'wind_speed' => (float) ($currentEntry['wind_speed'] ?? 0),
                'wind_gust' => (float) ($currentEntry['wind_gust_speed'] ?? 0),
                'wind_direction' => (float) ($currentEntry['wind_direction'] ?? 0),
                'humidity' => isset($currentEntry['relative_humidity']) && $currentEntry['relative_humidity'] !== null
                    ? (float) $currentEntry['relative_humidity']
                    : null,
                'precipitation' => (float) ($currentEntry['precipitation'] ?? 0),
            ],
        ];
    }

    /**
     * Bright Sky stamps entries with an offset (UTC unless asked), so the
     * hour is read in Europe/Berlin from the instant, not from the string.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function hourEntry(array $entry): array
    {
        $stamp = (string) ($entry['timestamp'] ?? '');
        $local = $stamp === '' ? null : CarbonImmutable::parse($stamp)->setTimezone('Europe/Berlin');
        $number = fn (string $key) => is_numeric($entry[$key] ?? null) ? (int) round((float) $entry[$key]) : null;

        return [
            'hour' => $local?->hour ?? 0,
            'time' => $local?->toIso8601String(),
            'precipitation' => (float) ($entry['precipitation'] ?? 0),
            'precipitation_probability' => $number('precipitation_probability'),
            'temperature' => is_numeric($entry['temperature'] ?? null) ? round((float) $entry['temperature'], 1) : null,
            'cloud_cover' => $number('cloud_cover'),
            'visibility' => $number('visibility'),
            // Bright Sky's icon names are the same set this app uses.
            'icon' => is_string($entry['icon'] ?? null) ? $entry['icon'] : null,
        ];
    }
}
