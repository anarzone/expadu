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
     * Cached with an explicit failure sentinel: Cache::remember never stores
     * null, so a down/slow Pegelonline API used to be re-attempted (2 × 3s
     * timeouts) on EVERY page that renders the widgets rail. Now a failure is
     * remembered for 2 minutes and the last good reading (kept 24h) is served
     * through an outage instead of blocking the request.
     *
     * @return array{level_cm: float, trend: string, status: string, timestamp: string}|null
     */
    public function getCurrentLevel(): ?array
    {
        $cached = Cache::get('rhine_level');

        if ($cached !== null) {
            return $cached === 'unavailable' ? Cache::get('rhine_level_stale') : $cached;
        }

        $level = $this->fetch();

        if ($level === null) {
            Cache::put('rhine_level', 'unavailable', 120);

            return Cache::get('rhine_level_stale');
        }

        Cache::put('rhine_level', $level, 900);
        Cache::put('rhine_level_stale', $level, 86400);

        return $level;
    }

    /**
     * @return array{level_cm: float, trend: string, status: string, timestamp: string}|null
     */
    private function fetch(): ?array
    {
        try {
            // Current measurement
            $current = Http::timeout(3)->connectTimeout(2)
                ->get(self::BASE_URL.'/stations/'.self::STATION_UUID.'/W/currentmeasurement.json')
                ->json();

            if (! $current || ! isset($current['value'])) {
                return null;
            }

            // Last 24h for trend
            $trend = 'stable';
            $measurements = Http::timeout(3)->connectTimeout(2)
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
    }
}
