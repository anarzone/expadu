<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for the Valhalla routing engine.
 * Computes real road-following routes with turn-by-turn directions.
 *
 * Costings: pedestrian, bicycle, auto, bus, multimodal
 */
class ValhallaRoutingService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.valhalla.url') ?? '', '/');
    }

    /**
     * Get a route between two points.
     *
     * @return array{duration_min: int, distance_km: float, geometry: string, steps: array, raw_seconds: int}|null
     */
    public function route(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng,
        string $costing = 'pedestrian',
    ): ?array {
        $cacheKey = "valhalla_route_{$costing}_".md5("{$fromLat},{$fromLng},{$toLat},{$toLng}");

        return Cache::remember($cacheKey, 300, function () use ($fromLat, $fromLng, $toLat, $toLng, $costing) {
            try {
                $response = Http::timeout(10)
                    ->post("{$this->baseUrl}/route", [
                        'locations' => [
                            ['lat' => $fromLat, 'lon' => $fromLng],
                            ['lat' => $toLat, 'lon' => $toLng],
                        ],
                        'costing' => $costing,
                        'directions_options' => [
                            'units' => 'kilometers',
                            'language' => 'en-US',
                        ],
                        'costing_options' => $this->getCostingOptions($costing),
                    ]);

                if (! $response->successful()) {
                    Log::info("Valhalla route failed: {$response->status()} for {$costing}");

                    return null;
                }

                $data = $response->json();
                $trip = $data['trip'] ?? null;

                if (! $trip || empty($trip['legs'])) {
                    return null;
                }

                $leg = $trip['legs'][0];
                $summary = $trip['summary'] ?? $leg['summary'] ?? [];

                return [
                    'duration_min' => max(1, (int) round(($summary['time'] ?? 0) / 60)),
                    'distance_km' => round($summary['length'] ?? 0, 1),
                    'geometry' => $leg['shape'] ?? '',
                    'raw_seconds' => (int) ($summary['time'] ?? 0),
                    'steps' => $this->parseManeuvers($leg['maneuvers'] ?? []),
                ];
            } catch (\Exception $e) {
                Log::warning("Valhalla routing error: {$e->getMessage()}");

                return null;
            }
        });
    }

    /**
     * Check if Valhalla is reachable and has routing tiles.
     */
    public function isAvailable(): bool
    {
        return Cache::remember('valhalla_available', 60, function () {
            try {
                $response = Http::timeout(3)->get("{$this->baseUrl}/status");

                return $response->successful();
            } catch (\Exception) {
                return false;
            }
        });
    }

    /**
     * Parse Valhalla maneuvers into simplified steps.
     *
     * @return array<int, array{instruction: string, distance_km: float, time_sec: int, type: string, emoji: string}>
     */
    private function parseManeuvers(array $maneuvers): array
    {
        return array_map(fn (array $m) => [
            'instruction' => $m['instruction'] ?? '',
            'distance_km' => round($m['length'] ?? 0, 2),
            'time_sec' => (int) ($m['time'] ?? 0),
            'type' => $this->maneuverTypeLabel($m['type'] ?? 0),
            'emoji' => $this->maneuverEmoji($m['type'] ?? 0),
        ], $maneuvers);
    }

    /**
     * @see https://valhalla.github.io/valhalla/api/turn-by-turn/api-reference/#maneuver-types
     */
    private function maneuverTypeLabel(int $type): string
    {
        return match ($type) {
            0 => 'none',
            1 => 'depart',
            2 => 'depart_right',
            3 => 'depart_left',
            4 => 'arrive',
            5 => 'arrive_right',
            6 => 'arrive_left',
            7 => 'continue',
            8 => 'slight_right',
            9 => 'right',
            10 => 'sharp_right',
            11 => 'u_turn_right',
            12 => 'u_turn_left',
            13 => 'sharp_left',
            14 => 'left',
            15 => 'slight_left',
            16 => 'straight',
            17, 18, 19, 20, 21, 22, 23, 24 => 'roundabout',
            25 => 'ferry',
            26 => 'exit_roundabout',
            default => 'continue',
        };
    }

    private function maneuverEmoji(int $type): string
    {
        return match ($type) {
            1, 2, 3 => '🚶',
            4, 5, 6 => '🏁',
            7, 16 => '⬆️',
            8 => '↗️',
            9 => '➡️',
            10 => '↘️',
            11, 12 => '↩️',
            13 => '↙️',
            14 => '⬅️',
            15 => '↖️',
            17, 18, 19, 20, 21, 22, 23, 24 => '🔄',
            25 => '⛴️',
            26 => '↗️',
            default => '⬆️',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function getCostingOptions(string $costing): array
    {
        return match ($costing) {
            'pedestrian' => ['pedestrian' => ['use_ferry' => 0.0, 'walking_speed' => 5.0]],
            'bicycle' => ['bicycle' => ['use_ferry' => 0.0, 'bicycle_type' => 'Hybrid']],
            'auto' => ['auto' => ['use_ferry' => 0.0, 'use_tolls' => 0.5]],
            default => [],
        };
    }
}
