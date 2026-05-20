<?php

namespace App\Services;

use App\Support\PerfLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Client for KVB Open Data API (data.webservice-kvb.koeln).
 * Free, no auth required, JSON responses.
 *
 * @see https://www.kvb.koeln/service/open_data.html
 */
class KvbApiService
{
    private const BASE = 'https://data.webservice-kvb.koeln/service/opendata';

    /**
     * All KVB stops with coordinates and line assignments.
     *
     * @return array<int, array{id: int, name: string, short: string, area: string, lines: string[], lat: float, lng: float}>
     */
    public function getStops(): array
    {
        return Cache::remember('kvb:stops', 3600, function () {
            $data = $this->fetch('haltestellen');
            if (! $data) {
                return [];
            }

            return collect($data['features'] ?? [])
                ->map(fn (array $f) => [
                    'id' => $f['properties']['ASS'] ?? 0,
                    'name' => $f['properties']['Name'] ?? '',
                    'short' => $f['properties']['Kurzname'] ?? '',
                    'area' => $f['properties']['Betriebsbereich'] ?? '',
                    'lines' => array_filter(explode(' ', $f['properties']['Linien'] ?? '')),
                    'lat' => $f['geometry']['coordinates'][1] ?? 0,
                    'lng' => $f['geometry']['coordinates'][0] ?? 0,
                ])
                ->filter(fn (array $s) => $s['name'] && $s['lat'])
                ->values()
                ->all();
        });
    }

    /**
     * Find stops by name.
     *
     * @return array<int, array{id: int, name: string, lines: string[], lat: float, lng: float}>
     */
    public function searchStops(string $query, int $limit = 10): array
    {
        return PerfLogger::measure('ext:kvb.searchStops', function () use ($query, $limit) {
            $q = mb_strtolower($query);

            return collect($this->getStops())
                ->filter(fn (array $s) => str_contains(mb_strtolower($s['name']), $q))
                ->unique('name')
                ->take($limit)
                ->values()
                ->all();
        });
    }

    /**
     * Find the nearest stop to coordinates.
     *
     * @return array{id: int, name: string, lines: string[], lat: float, lng: float}|null
     */
    public function nearestStop(float $lat, float $lng): ?array
    {
        return PerfLogger::measure('ext:kvb.nearestStop', function () use ($lat, $lng) {
            $stops = $this->getStops();
            $best = null;
            $bestDist = PHP_FLOAT_MAX;

            foreach ($stops as $s) {
                $dist = abs($s['lat'] - $lat) + abs($s['lng'] - $lng);
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $best = $s;
                }
            }

            return $best;
        });
    }

    /**
     * Current elevator disruptions (live, updated every few minutes).
     *
     * @return array<int, array{id: string, label: string, stop_area: string, lat: float, lng: float, timestamp: string}>
     */
    public function getElevatorDisruptions(): array
    {
        return Cache::remember('kvb:elevator_disruptions', 120, function () {
            $data = $this->fetch('aufzugsstoerung');
            if (! $data) {
                return [];
            }

            return collect($data['features'] ?? [])
                ->map(fn (array $f) => [
                    'id' => $f['properties']['Kennung'] ?? '',
                    'label' => $this->cleanEncoding($f['properties']['Bezeichnung'] ?? ''),
                    'stop_area' => $f['properties']['Haltestellenbereich'] ?? '',
                    'info' => $this->cleanEncoding($f['properties']['Info'] ?? ''),
                    'lat' => $f['geometry']['coordinates'][1] ?? 0,
                    'lng' => $f['geometry']['coordinates'][0] ?? 0,
                    'timestamp' => $f['properties']['timestamp'] ?? '',
                ])
                ->filter(fn (array $d) => $d['id'])
                ->values()
                ->all();
        });
    }

    /**
     * Current escalator disruptions (live).
     *
     * @return array<int, array{id: string, label: string, stop_area: string, lat: float, lng: float, timestamp: string}>
     */
    public function getEscalatorDisruptions(): array
    {
        return Cache::remember('kvb:escalator_disruptions', 120, function () {
            $data = $this->fetch('fahrtreppenstoerung');
            if (! $data) {
                return [];
            }

            return collect($data['features'] ?? [])
                ->map(fn (array $f) => [
                    'id' => $f['properties']['Kennung'] ?? '',
                    'label' => $this->cleanEncoding($f['properties']['Bezeichnung'] ?? ''),
                    'stop_area' => $f['properties']['Haltestellenbereich'] ?? '',
                    'info' => $this->cleanEncoding($f['properties']['Info'] ?? ''),
                    'lat' => $f['geometry']['coordinates'][1] ?? 0,
                    'lng' => $f['geometry']['coordinates'][0] ?? 0,
                    'timestamp' => $f['properties']['timestamp'] ?? '',
                ])
                ->filter(fn (array $d) => $d['id'])
                ->values()
                ->all();
        });
    }

    /**
     * All accessibility disruptions combined (elevators + escalators).
     *
     * @return array<int, array{type: string, id: string, label: string, stop_area: string, stop_name: string, lat: float, lng: float, timestamp: string}>
     */
    public function getAccessibilityDisruptions(): array
    {
        $stops = collect($this->getStops())->keyBy('id');

        $elevators = collect($this->getElevatorDisruptions())
            ->map(fn (array $d) => [
                ...$d,
                'type' => 'elevator',
                'stop_name' => $stops[$d['stop_area']]['name'] ?? "Stop #{$d['stop_area']}",
            ]);

        $escalators = collect($this->getEscalatorDisruptions())
            ->map(fn (array $d) => [
                ...$d,
                'type' => 'escalator',
                'stop_name' => $stops[$d['stop_area']]['name'] ?? "Stop #{$d['stop_area']}",
            ]);

        return $elevators->merge($escalators)->values()->all();
    }

    /**
     * Fetch a KVB Open Data endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $endpoint): ?array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['User-Agent' => 'Expadu/1.0 (Transit App for Expats)'])
                ->get(self::BASE."/{$endpoint}/json");

            if (! $response->successful()) {
                Log::info("KvbApi: {$endpoint} returned {$response->status()}");

                return null;
            }

            $body = $response->body();

            // KVB sometimes returns ISO-8859-1
            if (! mb_check_encoding($body, 'UTF-8')) {
                $body = mb_convert_encoding($body, 'UTF-8', 'ISO-8859-1');
            }

            return json_decode($body, true);
        } catch (\Exception $e) {
            Log::warning("KvbApi: {$endpoint} error — {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Fix encoding issues in KVB responses.
     */
    private function cleanEncoding(string $text): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
        }

        return html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    }
}
