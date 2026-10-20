<?php

namespace App\Services;

use App\Support\PerfLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
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

    private const STOPS_KEY = 'kvb:stops:v2';

    /** Fresh for an hour, then served stale (refreshed after the response) for up to a week. */
    private const STOPS_TTL = [3600, 7 * 86400];

    /** After a failed fetch, wait this long before the next try: one timeout per minute, not per request. */
    private const STOPS_RETRY_SECONDS = 60;

    /**
     * All KVB stops with coordinates and line assignments.
     *
     * The list changes rarely, so a board never waits on KVB once it has been
     * fetched: it is served stale-while-revalidate, a failed refresh keeps the
     * last good copy, and a failure is never cached as an empty list. Only a
     * cold cache fetches on the request, one worker at a time.
     *
     * @return array<int, array{id: int, name: string, short: string, area: string, lines: string[], lat: float, lng: float}>
     */
    public function getStops(): array
    {
        try {
            // Memoised: the ~460 kB list is unserialised once per request.
            $stale = Cache::memo()->get(self::STOPS_KEY);
            if ($stale !== null) {
                // A refresh that fails keeps the copy it was replacing for
                // another hour rather than throwing after every response.
                return Cache::memo()->flexible(self::STOPS_KEY, self::STOPS_TTL, function () use ($stale): array {
                    try {
                        return $this->fetchStopsOrFail();
                    } catch (KvbUnavailableException) {
                        return $stale;
                    }
                });
            }

            // Cold: one fetch at a time. The others wait, then read what it stored.
            return Cache::lock('kvb:stops:fetch', 20)->block(
                12,
                fn () => Cache::flexible(self::STOPS_KEY, self::STOPS_TTL, fn () => $this->fetchStopsOrFail()),
            );
        } catch (KvbUnavailableException|LockTimeoutException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, name: string, short: string, area: string, lines: string[], lat: float, lng: float}>
     *
     * @throws KvbUnavailableException
     */
    private function fetchStopsOrFail(): array
    {
        if (Cache::has('kvb:stops:retry-after')) {
            throw new KvbUnavailableException('KVB stop list failed recently');
        }

        $data = $this->fetch('haltestellen', timeout: 10);
        $stops = $data ? $this->mapStops($data) : [];

        if ($stops === []) {
            Cache::put('kvb:stops:retry-after', true, self::STOPS_RETRY_SECONDS);

            throw new KvbUnavailableException('KVB stop list unavailable');
        }

        return $stops;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array{id: int, name: string, short: string, area: string, lines: string[], lat: float, lng: float}>
     */
    private function mapStops(array $data): array
    {
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
     * A KVB point's station number. Point ids are the station's number
     * ("Haltestellenbereich", which the lift feed names) followed by a mode
     * digit and a point digit: Neumarkt is 2, its points 211…218 and 221/222.
     */
    public static function stationNumber(int|string $pointId): int
    {
        return intdiv((int) $pointId, 100);
    }

    /**
     * Every KVB point of the station with this name, as KVB lists it.
     *
     * @return list<array{id: int, name: string, short: string, area: string, lines: string[], lat: float, lng: float}>
     */
    public function stationPoints(string $name): array
    {
        return array_values(array_filter($this->getStops(), fn (array $stop) => $stop['name'] === $name));
    }

    /**
     * The lift disruptions as last fetched, without fetching: null when they
     * aren't known (nothing cached yet). A miss queues a refresh, so a page
     * never waits on KVB for them.
     *
     * @return list<array{id: string, label: string, stop_area: string, info: string, lat: float, lng: float, timestamp: string}>|null
     */
    public function knownElevatorDisruptions(): ?array
    {
        $known = Cache::get('kvb:elevator_disruptions');
        if (is_array($known)) {
            return $known;
        }

        dispatch(fn () => app(self::class)->getElevatorDisruptions())->afterResponse();

        return null;
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
        // The feeds name the station number, not a point id (see stationNumber).
        $stations = collect($this->getStops())
            ->mapWithKeys(fn (array $stop) => [self::stationNumber($stop['id']) => $stop['name']]);

        $elevators = collect($this->getElevatorDisruptions())
            ->map(fn (array $d) => [
                ...$d,
                'type' => 'elevator',
                'stop_name' => $stations[(int) $d['stop_area']] ?? "Stop #{$d['stop_area']}",
            ]);

        $escalators = collect($this->getEscalatorDisruptions())
            ->map(fn (array $d) => [
                ...$d,
                'type' => 'escalator',
                'stop_name' => $stations[(int) $d['stop_area']] ?? "Stop #{$d['stop_area']}",
            ]);

        return $elevators->merge($escalators)->values()->all();
    }

    /**
     * Fetch a KVB Open Data endpoint.
     *
     * @return array<string, mixed>|null
     */
    private function fetch(string $endpoint, int $timeout = 15): ?array
    {
        try {
            $response = Http::timeout($timeout)
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
