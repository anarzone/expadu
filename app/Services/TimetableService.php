<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Builds the home "Departures" board: one board per mode tab, each rooted at
 * the nearest stop *serving that mode*. KVB Open Data gives every stop a mode
 * (519 tram + 1,636 bus stops), so "Bus" can resolve to a different, closer
 * bus stop than the tram stop — exactly the behaviour the tabs promise.
 * Departures themselves come from VRS TRIAS real-time via GtfsDepartureService
 * (already grouped per line with the next + following minutes and delays).
 */
class TimetableService
{
    private const WALK_KMH = 4.5;

    public function __construct(
        private readonly KvbApiService $kvb,
        private readonly GtfsDepartureService $gtfs,
    ) {}

    /**
     * Stale-while-revalidate: after the first build, every request is served
     * from cache instantly. Fresh for 25s; between 25s and 180s the stale
     * boards return immediately while a deferred background refresh rebuilds
     * them. Nobody waits on the TRIAS round-trip except the very first
     * visitor of a location cell.
     *
     * Cached departures store minutes AS FETCHED plus a fetched_at stamp;
     * adjustForAge() shifts them to true minutes at request time — so a
     * cache hit of any age still shows correct countdowns (a "7 min" tram
     * cached 2 minutes ago renders as "5 min", and departed rows drop off).
     *
     * @return array{all: ?array, tram: ?array, bus: ?array, rail: ?array}
     */
    public function boards(float $lat, float $lng): array
    {
        $boards = Cache::flexible(
            sprintf('timetable:%.3f_%.3f', $lat, $lng),
            [25, 180],
            fn () => $this->build($lat, $lng),
        );

        // `?? null` tolerates a stale cached build from before `rail` existed
        // during a deploy — it fills in on the next rebuild.
        return [
            'all' => $this->adjustForAge($boards['all']),
            'tram' => $this->adjustForAge($boards['tram']),
            'bus' => $this->adjustForAge($boards['bus']),
            'rail' => $this->adjustForAge($boards['rail'] ?? null),
        ];
    }

    /**
     * Re-anchor a cached board to "now": subtract the cache age from every
     * departure minute, drop what already left, and hide the stamp.
     *
     * @param  array<string, mixed>|null  $board
     * @return array<string, mixed>|null
     */
    private function adjustForAge(?array $board): ?array
    {
        if ($board === null) {
            return null;
        }

        $elapsedMin = intdiv(max(0, time() - (int) ($board['fetched_at'] ?? time())), 60);
        unset($board['fetched_at']);

        if ($elapsedMin === 0) {
            return $board;
        }

        $board['departures'] = collect($board['departures'])
            ->map(function (array $dep) use ($elapsedMin) {
                $dep['minutes'] = array_values(array_filter(
                    array_map(fn ($m) => $m - $elapsedMin, $dep['minutes']),
                    fn ($m) => $m >= 0,
                ));

                return $dep;
            })
            ->filter(fn (array $dep) => $dep['minutes'] !== [] || $dep['cancelled'])
            ->values()
            ->all();

        return $board;
    }

    /**
     * @return array{all: ?array, tram: ?array, bus: ?array, rail: ?array}
     */
    private function build(float $lat, float $lng): array
    {
        $stops = $this->kvb->getStops();

        if ($stops === []) {
            return ['all' => null, 'tram' => null, 'bus' => null, 'rail' => null];
        }

        $picks = [
            'all' => $this->nearest($stops, $lat, $lng, null),
            'tram' => $this->nearest($stops, $lat, $lng, 'STRAB'),
            'bus' => $this->nearest($stops, $lat, $lng, 'BUS'),
        ];

        // "All" and a mode tab usually resolve to the same stop — fetch
        // departures once per unique stop, not once per tab. Each stop is its
        // own SWR cache entry: a user whose GPS jitters into a fresh location
        // cell (or a different user nearby) reuses the hot stop data instead
        // of waiting on TRIAS, and the 30s board polls double as the warmer
        // that keeps active stops perpetually fresh in the background.
        $departuresByStop = [];
        foreach ($picks as $pick) {
            $name = $pick['name'] ?? null;
            if ($name === null || isset($departuresByStop[$name])) {
                continue;
            }

            try {
                // fetched_at anchors the cached minutes so any later read can
                // shift them to true countdowns (see adjustForAge).
                $departuresByStop[$name] = Cache::flexible(
                    'board_stop:'.$name,
                    [20, 300],
                    fn () => ['fetched_at' => time()] + $this->gtfs->getDepartures($name, 20),
                );
            } catch (\Throwable) {
                $departuresByStop[$name] = ['departures' => [], 'source' => 'unavailable', 'fetched_at' => time()];
            }
        }

        return [
            'all' => $this->board($picks['all'], $departuresByStop, null),
            'tram' => $this->board($picks['tram'], $departuresByStop, 'tram'),
            'bus' => $this->board($picks['bus'], $departuresByStop, 'bus'),
            // S/RE/RB rail has no KVB stop of its own (KVB Open Data is tram +
            // bus only), so it surfaces at the nearest combined station — ride
            // the rail board off the "all" pick and let the tab hide itself
            // when that stop has no trains.
            'rail' => $this->board($picks['all'], $departuresByStop, 'rail'),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<string, mixed>|null
     */
    private function nearest(array $stops, float $lat, float $lng, ?string $area): ?array
    {
        $best = null;
        $bestKm = INF;

        foreach ($stops as $stop) {
            if ($area !== null && ($stop['area'] ?? null) !== $area) {
                continue;
            }
            if (! isset($stop['lat'], $stop['lng'])) {
                continue;
            }

            $km = $this->haversineKm($lat, $lng, (float) $stop['lat'], (float) $stop['lng']);
            if ($km < $bestKm) {
                $bestKm = $km;
                $best = $stop + ['_km' => $km];
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>|null  $stop
     * @param  array<string, array<string, mixed>>  $departuresByStop  prefetched per unique stop name
     * @return array<string, mixed>|null
     */
    private function board(?array $stop, array $departuresByStop, ?string $filterType): ?array
    {
        if ($stop === null) {
            return null;
        }

        $result = $departuresByStop[$stop['name']] ?? ['departures' => [], 'source' => 'unavailable'];

        // Cologne has no separate metro: KVB Stadtbahn runs as a street tram and
        // dives underground downtown, where VRS may report it as "metro"/subway.
        // Fold both under the Tram tab so a Stadtbahn never vanishes from it.
        $allowedTypes = match ($filterType) {
            'tram' => ['tram', 'subway'],
            'bus' => ['bus'],
            'rail' => ['rail'],
            default => null,
        };

        $departures = collect($result['departures'] ?? [])
            ->when($allowedTypes !== null, fn ($c) => $c->filter(fn ($d) => in_array($d['type'] ?? '', $allowedTypes, true)))
            ->map(fn ($d) => [
                'line' => (string) ($d['line'] ?? '?'),
                'destination' => (string) ($d['direction'] ?? ''),
                'type' => (string) ($d['type'] ?? ''),
                'color' => $this->color($d),
                // A departure board answers "can I catch something soon?" —
                // beyond a 2h horizon ("740 min") it's noise, so those rows
                // drop off entirely.
                'minutes' => array_values(array_filter(
                    (array) ($d['departures'] ?? []),
                    fn ($m) => is_numeric($m) && $m <= 120,
                )),
                'delay' => (int) ($d['delay'] ?? 0),
                'cancelled' => (bool) ($d['cancelled'] ?? false),
                'disrupted' => (bool) ($d['disrupted'] ?? false),
            ])
            ->filter(fn ($d) => $d['minutes'] !== [])
            ->map(function (array $d) use ($stop) {
                // GTFS-static route context: travel direction (groups the board
                // into lanes) + the next stops ridden ("via …"). Degrades to
                // null/[] when GTFS can't match the live headsign.
                try {
                    $context = $this->gtfs->routeContext($stop['name'], $d['line'], $d['destination']);
                } catch (\Throwable) {
                    $context = ['direction' => null, 'via' => []];
                }

                return $d + ['direction' => $context['direction'], 'via' => $context['via']];
            })
            ->values()
            ->all();

        return [
            'stop_name' => $stop['name'],
            'walk_min' => max(1, (int) ceil(($stop['_km'] ?? 0) / self::WALK_KMH * 60)),
            'source' => $result['source'] ?? 'gtfs',
            'fetched_at' => (int) ($result['fetched_at'] ?? time()),
            'departures' => $departures,
        ];
    }

    /**
     * Prefer a real GTFS route_color when present; otherwise the KVB map.
     *
     * @param  array<string, mixed>  $dep
     */
    private function color(array $dep): string
    {
        $color = $dep['color'] ?? null;
        if (is_string($color) && $color !== '' && strtoupper($color) !== '#1A4CD4') {
            return $color;
        }

        return KvbLineColors::for((string) ($dep['line'] ?? ''), $dep['type'] ?? null);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
