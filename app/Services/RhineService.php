<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Rhine water level data from Pegelonline WSV (free, no auth).
 * Cologne gauge station: a6ee8177-107b-47dd-bcfd-30960ccc6e9c
 */
class RhineService
{
    private const STATION_UUID = 'a6ee8177-107b-47dd-bcfd-30960ccc6e9c';

    private const BASE_URL = 'https://www.pegelonline.wsv.de/webservices/rest-api/v2';

    /**
     * @return array{level_cm: float, trend: string, status: string, timestamp: string}|null
     */
    public function getCurrentLevel(): ?array
    {
        return Cache::remember('rhine_level', 900, function () {
            try {
                // Current measurement
                $current = Http::timeout(5)
                    ->get(self::BASE_URL.'/stations/'.self::STATION_UUID.'/W/currentmeasurement.json')
                    ->json();

                if (! $current || ! isset($current['value'])) {
                    return null;
                }

                // Last 24h for trend
                $trend = 'stable';
                $measurements = Http::timeout(5)
                    ->get(self::BASE_URL.'/stations/'.self::STATION_UUID.'/W/measurements.json?start=P1D')
                    ->json();

                if (is_array($measurements) && count($measurements) >= 6) {
                    $now = end($measurements)['value'] ?? $current['value'];
                    $sixHoursAgo = $measurements[max(0, count($measurements) - 6)]['value'] ?? $now;
                    $diff = $now - $sixHoursAgo;
                    $trend = $diff > 5 ? 'rising' : ($diff < -5 ? 'falling' : 'stable');
                }

                // Status from API
                $status = match ($current['stateMnwMhw'] ?? 'unknown') {
                    'normal' => 'normal',
                    'low' => 'low',
                    'high' => 'high',
                    default => 'normal',
                };

                // Override with extreme levels
                if (($current['stateNswHsw'] ?? '') === 'high') {
                    $status = 'warning';
                }

                return [
                    'level_cm' => (float) $current['value'],
                    'trend' => $trend,
                    'status' => $status,
                    'timestamp' => $current['timestamp'] ?? now()->toIso8601String(),
                ];
            } catch (\Exception) {
                return null;
            }
        });
    }
}
