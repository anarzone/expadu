<?php

namespace App\Services\Weather;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * wttr.in — free console-friendly weather service that returns JSON.
 *
 * Wraps WorldWeatherOnline data. Provides a real FeelsLikeC field so we
 * don't need to estimate apparent temperature. No API key required.
 *
 * Docs: https://github.com/chubin/wttr.in
 */
class WttrInProvider implements WeatherProvider
{
    public function name(): string
    {
        return 'wttrin';
    }

    public function fetch(float $lat, float $lng): ?array
    {
        try {
            $response = Http::timeout(5)
                ->retry(2, 500, throw: false)
                ->get("https://wttr.in/{$lat},{$lng}", [
                    'format' => 'j1',
                ]);

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();
            $currentList = $data['current_condition'] ?? [];
            $current = $currentList[0] ?? null;

            if (! is_array($current)) {
                return null;
            }

            $weatherCode = (int) ($current['weatherCode'] ?? 113);
            $hourlySlots = array_merge(
                $data['weather'][0]['hourly'] ?? [],
                $data['weather'][1]['hourly'] ?? [],
            );

            return [
                'current' => [
                    'temperature' => (float) ($current['temp_C'] ?? 0),
                    'feels_like' => isset($current['FeelsLikeC'])
                        ? (float) $current['FeelsLikeC']
                        : null,
                    'icon' => $this->codeToIcon($weatherCode),
                    'wind_speed' => (float) ($current['windspeedKmph'] ?? 0),
                    'wind_gust' => $this->currentHourGust($hourlySlots),
                    'wind_direction' => (float) ($current['winddirDegree'] ?? 0),
                    'humidity' => isset($current['humidity'])
                        ? (float) $current['humidity']
                        : null,
                    'precipitation' => (float) ($current['precipMM'] ?? 0),
                ],
                'hourly' => $this->buildHourly($hourlySlots),
            ];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * wttr.in's `current_condition` block has no gust field. Their hourly
     * forecast slots (3-hour buckets) do — `WindGustKmph` from the slot
     * covering the current hour is the closest "now" gust we can get from
     * the same provider/model, keeping data physically consistent.
     */
    private function currentHourGust(array $slots): float
    {
        $currentHour = (int) now('Europe/Berlin')->hour;
        // Slots are 3-hour buckets. Find the largest slot whose hour <= now.
        $bestGust = 0.0;
        $bestHour = -1;

        foreach ($slots as $slot) {
            $slotHour = (int) floor(((int) ($slot['time'] ?? 0)) / 100);
            if ($slotHour <= $currentHour && $slotHour > $bestHour) {
                $bestHour = $slotHour;
                $bestGust = (float) ($slot['WindGustKmph'] ?? 0);
            }
        }

        return $bestGust;
    }

    /**
     * wttr.in / WorldWeatherOnline returns forecast in 3-hour slots. Expand
     * to hourly-ish entries matching the hour of each slot's start time.
     *
     * @return list<array{hour: int, precipitation: float}>
     */
    private function buildHourly(array $slots): array
    {
        $result = [];

        foreach ($slots as $slot) {
            $rawTime = (string) ($slot['time'] ?? '0');
            // Time is formatted like "0", "300", "600"... — hours as integer, 24h format.
            $hour = (int) floor(((int) $rawTime) / 100);

            $result[] = [
                'hour' => $hour,
                'precipitation' => (float) ($slot['precipMM'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * Map WorldWeatherOnline weather codes to our internal icon vocabulary.
     *
     * Reference: https://www.worldweatheronline.com/feed/wwoConditionCodes.xml
     */
    private function codeToIcon(int $code): string
    {
        $isDay = now('Europe/Berlin')->hour >= 6 && now('Europe/Berlin')->hour < 20;

        return match (true) {
            $code === 113 => $isDay ? 'clear-day' : 'clear-night',
            $code === 116 => $isDay ? 'partly-cloudy-day' : 'partly-cloudy-night',
            in_array($code, [119, 122], true) => 'cloudy',
            in_array($code, [143, 248, 260], true) => 'fog',
            in_array($code, [176, 263, 266, 281, 284, 293, 296, 299, 302, 305, 308, 311, 314, 353, 356, 359], true) => 'rain',
            in_array($code, [179, 182, 185, 317, 320, 362, 365, 374, 377], true) => 'sleet',
            in_array($code, [227, 230, 323, 326, 329, 332, 335, 338, 368, 371, 392, 395], true) => 'snow',
            in_array($code, [200, 386, 389], true) => 'thunderstorm',
            default => 'cloudy',
        };
    }
}
