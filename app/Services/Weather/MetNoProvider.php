<?php

namespace App\Services\Weather;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Met.no (Norwegian Meteorological Institute) — free, no key, global coverage.
 *
 * Docs: https://api.met.no/weatherapi/locationforecast/2.0/documentation
 *
 * Requires a descriptive User-Agent header. Uses the compact endpoint which
 * returns ~48 hourly entries. Wind is in m/s → convert to km/h for contract.
 */
class MetNoProvider implements WeatherProvider
{
    private const USER_AGENT = 'expadu-app github.com/anarzone/expadu';

    public function name(): string
    {
        return 'metno';
    }

    public function fetch(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(2, 500, throw: false)
                ->withHeaders(['User-Agent' => self::USER_AGENT])
                ->get('https://api.met.no/weatherapi/locationforecast/2.0/compact', [
                    'lat' => $lat,
                    'lon' => $lng,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $timeseries = $response->json('properties.timeseries') ?? [];

            if (empty($timeseries)) {
                return null;
            }

            $first = $timeseries[0];
            $instant = $first['data']['instant']['details'] ?? [];
            $symbolCode = $first['data']['next_1_hours']['summary']['symbol_code']
                ?? $first['data']['next_6_hours']['summary']['symbol_code']
                ?? 'clearsky_day';
            $currentPrecip = (float) ($first['data']['next_1_hours']['details']['precipitation_amount']
                ?? $first['data']['next_6_hours']['details']['precipitation_amount']
                ?? 0);

            return [
                'current' => [
                    'temperature' => (float) ($instant['air_temperature'] ?? 0),
                    'feels_like' => null,
                    'icon' => $this->symbolToIcon($symbolCode),
                    'wind_speed' => (float) ($instant['wind_speed'] ?? 0) * 3.6,
                    'wind_gust' => 0.0, // not available in compact endpoint
                    'wind_direction' => (float) ($instant['wind_from_direction'] ?? 0),
                    'humidity' => isset($instant['relative_humidity'])
                        ? (float) $instant['relative_humidity']
                        : null,
                    'precipitation' => $currentPrecip,
                ],
                'hourly' => $this->buildHourly($timeseries),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return list<array{hour: int, precipitation: float}>
     */
    private function buildHourly(array $timeseries): array
    {
        $today = now('Europe/Berlin')->toDateString();
        $hourly = [];

        foreach ($timeseries as $entry) {
            $time = (string) ($entry['time'] ?? '');
            if ($time === '') {
                continue;
            }

            $local = Carbon::parse($time)->setTimezone('Europe/Berlin');

            if ($local->toDateString() !== $today) {
                continue;
            }

            $hourly[] = [
                'hour' => $local->hour,
                'precipitation' => (float) ($entry['data']['next_1_hours']['details']['precipitation_amount'] ?? 0),
            ];
        }

        return $hourly;
    }

    /**
     * Map Met.no symbol codes to our internal icon vocabulary.
     *
     * Symbol reference: https://api.met.no/weatherapi/weathericon/2.0/documentation
     */
    private function symbolToIcon(string $symbol): string
    {
        $base = preg_replace('/_(day|night|polartwilight)$/', '', $symbol) ?? $symbol;
        $isNight = str_ends_with($symbol, '_night');

        return match (true) {
            $base === 'clearsky' => $isNight ? 'clear-night' : 'clear-day',
            in_array($base, ['fair', 'partlycloudy'], true) => $isNight ? 'partly-cloudy-night' : 'partly-cloudy-day',
            $base === 'cloudy' => 'cloudy',
            $base === 'fog' => 'fog',
            str_contains($base, 'thunder') => 'thunderstorm',
            str_contains($base, 'sleet') => 'sleet',
            str_contains($base, 'snow') => 'snow',
            str_contains($base, 'rain') => 'rain',
            default => 'cloudy',
        };
    }
}
