<?php

namespace App\Services;

use App\Jobs\RefreshWeather;
use App\Services\Weather\BrightSkyProvider;
use App\Services\Weather\MetNoProvider;
use App\Services\Weather\OpenMeteoProvider;
use App\Services\Weather\WeatherProvider;
use App\Services\Weather\WttrInProvider;
use App\Support\PerfLogger;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
        // Ordered by data quality (hourly granularity) then reliability:
        //  1. Open-Meteo  — true hourly data, sun-aware apparent temp, best for forecast
        //  2. Bright Sky  — DWD hourly observations, no feels_like
        //  3. wttr.in     — reliable but only 3-hour slots (misses short rain windows)
        //  4. Met.no      — Norwegian Met Institute, hourly but no wind gust
        $this->providers = $providers ?? [
            app(OpenMeteoProvider::class),
            app(BrightSkyProvider::class),
            app(WttrInProvider::class),
            app(MetNoProvider::class),
        ];
    }

    /**
     * Get current weather. Uses DWD (Bright Sky) with Met.no fallback.
     */
    public function getCurrentWeather(float $lat = 50.9375, float $lng = 6.9603): array
    {
        return PerfLogger::measure('ext:weather.getCurrentWeather', function () use ($lat, $lng) {
            return $this->buildCurrentWeather($lat, $lng);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCurrentWeather(float $lat, float $lng): array
    {
        $data = $this->fetch($lat, $lng);
        $current = $data['current'] ?? null;

        // No data, or a partial reading missing the temperature, is treated as
        // unavailable — never render a misleading 0°.
        if (! $current || ($current['temperature'] ?? null) === null) {
            return $this->unavailableWeather();
        }

        $icon = $current['icon'];

        return [
            'available' => true,
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
        return PerfLogger::measure('ext:weather.getForecast', function () use ($lat, $lng) {
            return $this->buildForecast($lat, $lng);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildForecast(float $lat, float $lng): array
    {
        $data = $this->fetch($lat, $lng);
        $now = now('Europe/Berlin');
        $upcoming = $this->fromCurrentHour($data['hourly'] ?? [], $now);
        $rainStart = null;

        // The rain start looks past the current hour.
        $nextHour = $now->copy()->startOfHour()->addHour()->getTimestamp();
        foreach ($upcoming as $i => $entry) {
            $later = isset($entry['time']) ? Carbon::parse($entry['time'])->getTimestamp() >= $nextHour : ($i > 0 || $entry['hour'] !== $now->hour);
            if ($later && $entry['precipitation'] > 0.1) {
                $rainStart = str_pad((string) $entry['hour'], 2, '0', STR_PAD_LEFT).':00';
                break;
            }
        }

        $current = $this->getCurrentWeather($lat, $lng);
        $isRainingNow = ($current['precipitation'] ?? 0) > 0
            || in_array($current['icon'] ?? '', ['rain', 'sleet', 'thunderstorm', 'hail']);
        $bikeScore = $this->calculateBikeScore($current, $rainStart, $isRainingNow);

        // Next-hours forecast: the current hour + the next 7 = 8 slots.
        $nextHours = array_map(fn (array $entry) => $this->hourSlot($entry), array_slice($upcoming, 0, 8));

        // Smart rain summary from hourly data
        $rainSummary = $this->buildRainSummary($nextHours, $isRainingNow);

        // Rain within the actionable near-term window — the same 8 slots the
        // summary describes — NOT merely some later hour of the day. `rain_starts`
        // scans the whole rest of the day, so on a dry morning it can point at
        // late-evening rain; framing the feed "rainy" off that contradicted the
        // "Dry next N hours" the weather widget shows. This stays consistent.
        $rainSoon = $isRainingNow
            || array_filter($nextHours, fn ($h) => $h['precip'] > 0.1) !== [];

        return [
            'available' => $current['available'] ?? false,
            'rain_starts' => $isRainingNow && ! $rainStart ? 'now' : $rainStart,
            'rain_soon' => $rainSoon,
            'bike_score' => $bikeScore,
            'rain_summary' => $rainSummary,
            'hourly' => $nextHours,
            'sun' => $data['sun'][$now->toDateString()] ?? null,
        ];
    }

    /**
     * One day's weather hour by hour, for the day a page shows (Events' sky,
     * My day's free hours). A past day has no recorded weather and a day past
     * the forecast has none either: both say so instead of showing anything.
     *
     * @return array{available: bool, reason: string|null, date: string, timezone: string, sunrise: string|null, sunset: string|null, hours: list<array<string, mixed>>}
     */
    public function getDayForecast(string $date, float $lat = 50.9375, float $lng = 6.9603): array
    {
        $tz = 'Europe/Berlin';
        $empty = ['available' => false, 'reason' => null, 'date' => $date, 'timezone' => $tz, 'sunrise' => null, 'sunset' => null, 'hours' => []];
        if ($date < now($tz)->toDateString()) {
            return [...$empty, 'reason' => 'past'];
        }

        $data = $this->fetch($lat, $lng);
        $hours = array_values(array_filter(
            $data['hourly'] ?? [],
            fn (array $entry) => isset($entry['time']) && Carbon::parse($entry['time'])->setTimezone($tz)->toDateString() === $date,
        ));
        if ($hours === []) {
            return [...$empty, 'reason' => $data === [] ? 'unavailable' : 'beyond_forecast'];
        }

        return [
            ...$empty,
            'available' => true,
            'sunrise' => $data['sun'][$date]['sunrise'] ?? null,
            'sunset' => $data['sun'][$date]['sunset'] ?? null,
            'hours' => array_map(fn (array $entry) => $this->hourSlot($entry), $hours),
        ];
    }

    /**
     * The hourly entries from the current hour on. Providers that give real
     * instants are cut by time (so a 25-hour day and midnight are exact);
     * hour-only ones by the clock hour, as before.
     *
     * @param  list<array<string, mixed>>  $hourly
     * @return list<array<string, mixed>>
     */
    private function fromCurrentHour(array $hourly, CarbonInterface $now): array
    {
        if ($hourly !== [] && isset($hourly[0]['time'])) {
            $hourStart = $now->copy()->startOfHour()->getTimestamp();

            return array_values(array_filter(
                $hourly,
                fn (array $entry) => isset($entry['time']) && Carbon::parse($entry['time'])->getTimestamp() >= $hourStart,
            ));
        }

        $result = [];
        foreach ($hourly as $entry) {
            if ($result === [] && $entry['hour'] < $now->hour) {
                continue;
            }
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function hourSlot(array $entry): array
    {
        $icon = $entry['icon'] ?? null;

        return [
            'hour' => str_pad((string) $entry['hour'], 2, '0', STR_PAD_LEFT).':00',
            'time' => $entry['time'] ?? null,
            'precip' => round((float) $entry['precipitation'], 1),
            'precip_probability' => $entry['precipitation_probability'] ?? null,
            'temperature' => $entry['temperature'] ?? null,
            'cloud_cover' => $entry['cloud_cover'] ?? null,
            // Metres. Under 1 000 m is fog by the usual definition.
            'visibility' => $entry['visibility'] ?? null,
            'icon' => $icon,
            'condition' => $icon === null ? null : $this->iconToCondition($icon),
        ];
    }

    /**
     * The current reading only if a fresh one is cached: never fetches and
     * never queues a refresh, so callers on a write path pay nothing.
     *
     * @return array<string, mixed>|null
     */
    public function cachedCurrent(float $lat = 50.9375, float $lng = 6.9603): ?array
    {
        $fresh = Cache::get("weather_fresh:{$lat}_{$lng}");

        return is_array($fresh) && is_array($fresh['current'] ?? null) ? $fresh['current'] : null;
    }

    /**
     * Stale-while-revalidate read. Returns fresh cache if available; otherwise
     * returns stale cache and queues a background refresh; otherwise fetches
     * synchronously with fast-fail provider iteration.
     *
     * @return array{current: array<string, mixed>, hourly: list<array{hour: int, precipitation: float}>}|array{}
     */
    protected function fetch(float $lat, float $lng): array
    {
        $freshKey = "weather_fresh:{$lat}_{$lng}";
        $staleKey = "weather_stale:{$lat}_{$lng}";

        $fresh = Cache::get($freshKey);
        if (is_array($fresh) && ! empty($fresh)) {
            return $fresh;
        }

        // Weather must NEVER block the page. The request path only reads cache
        // and queues a background refresh — it never runs the slow, network-
        // bound provider chain synchronously. A cold cache returns empty, so
        // the UI shows an honest "unavailable" until the queued refresh lands
        // (fetchSync runs only inside the RefreshWeather job).
        try {
            RefreshWeather::dispatch($lat, $lng);
        } catch (Throwable $e) {
            Log::warning('Weather refresh dispatch failed', ['err' => $e->getMessage()]);
        }

        $stale = Cache::get($staleKey);

        return is_array($stale) && ! empty($stale) ? $stale : [];
    }

    /**
     * Synchronous fetch through the provider chain with per-provider failure
     * cooldown. Public so RefreshWeather can drive it from the queue worker.
     *
     * @return array{current: array<string, mixed>, hourly: list<array{hour: int, precipitation: float}>}|array{}
     */
    public function fetchSync(float $lat, float $lng): array
    {
        foreach ($this->providers as $provider) {
            $name = $provider->name();
            if (Cache::has("weather_blacklist:{$name}")) {
                continue;
            }

            $data = $provider->fetch($lat, $lng);

            if ($data !== null) {
                $this->cacheResult($lat, $lng, $data);

                return $data;
            }

            // Blacklist for 5 min so we skip the slow path on subsequent reads.
            Cache::put("weather_blacklist:{$name}", true, 300);
            Log::warning("Weather provider {$name} failed, cooled down 5min");
        }

        Log::error("All weather providers failed for ({$lat}, {$lng})");

        return [];
    }

    /**
     * @param  array{current: array<string, mixed>, hourly: list<array{hour: int, precipitation: float}>}  $data
     */
    private function cacheResult(float $lat, float $lng, array $data): void
    {
        Cache::put("weather_fresh:{$lat}_{$lng}", $data, 300);
        Cache::put("weather_stale:{$lat}_{$lng}", $data, 86400);
    }

    /**
     * Placeholder values when every provider fails. The `condition` is the
     * signal consumers can use to show a "weather unavailable" state.
     */
    protected function unavailableWeather(): array
    {
        return [
            'available' => false,
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
