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
        $tram = $this->adjustForAge($boards['tram']);
        $bus = $this->adjustForAge($boards['bus']);

        return [
            // "All" folds the coord-resolved tram + bus boards into the hub
            // board so an interchange shows every mode, not just the one its
            // name resolves to (rail at Köln Hbf). Age-adjusted first, so the
            // three sets of minutes share a clock and merge cleanly.
            'all' => $this->mergeAll($this->adjustForAge($boards['all']), $tram, $bus),
            'tram' => $tram,
            'bus' => $bus,
            'rail' => $this->adjustForAge($boards['rail'] ?? null),
        ];
    }

    /**
     * Fold the coord-resolved tram + bus boards into the hub board for the "All"
     * tab. A hub NAME resolves to a single StopPlace — the rail station at Köln
     * Hbf — so without this "All" hides the tram + bus platforms the mode tabs
     * surface. Dedup by line + destination + type (a plain stop resolves all
     * three inputs to the same departures), drop the direction (the rows come
     * from different platforms, so per-stop lane grouping would be incoherent —
     * the mode tabs keep their lanes), and order soonest-first so the board
     * header and "next departure" logic read the imminent mode.
     *
     * The inputs are already age-adjusted to "now", so their minutes share a
     * clock — the reason this merges here rather than inside the cached build().
     *
     * @param  array<string, mixed>|null  $all  the hub board (null = no stop nearby)
     * @param  array<string, mixed>|null  $tram
     * @param  array<string, mixed>|null  $bus
     * @return array<string, mixed>|null
     */
    private function mergeAll(?array $all, ?array $tram, ?array $bus): ?array
    {
        if ($all === null) {
            return null;
        }

        $seen = [];
        $rows = [];

        foreach (array_filter([$all, $tram, $bus]) as $board) {
            foreach ($board['departures'] ?? [] as $dep) {
                $key = ($dep['line'] ?? '').'|'.($dep['destination'] ?? '').'|'.($dep['type'] ?? '');
                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $dep['direction'] = null;
                $rows[] = $dep;
            }
        }

        usort($rows, fn (array $a, array $b) => ($a['minutes'][0] ?? PHP_INT_MAX) <=> ($b['minutes'][0] ?? PHP_INT_MAX));

        return ['departures' => $rows] + $all;
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

        $allPick = $this->nearest($stops, $lat, $lng, null);
        $tramPick = $this->nearest($stops, $lat, $lng, 'STRAB');
        $busPick = $this->nearest($stops, $lat, $lng, 'BUS');

        // A stop NAME is ambiguous at an interchange: "Dom/Hbf" resolves in TRIAS
        // to the Hauptbahnhof *rail* StopPlace (de:…:11201), which hides the
        // Stadtbahn/bus platforms (de:…:11211) that share the name — so the Tram
        // and Bus tabs came back empty at hubs. Fetch tram + bus by their precise
        // platform COORDINATES (the correct StopPlace per mode). "all"/"rail"
        // keep the hub NAME: rail has no KVB stop of its own and rides the
        // station the name resolves to. Each is its own SWR cache entry, warmed
        // by the 30s board poll.
        $hub = $allPick ? $this->departuresByName($allPick['name']) : null;
        $tram = $tramPick ? $this->departuresByCoords((float) $tramPick['lat'], (float) $tramPick['lng']) : null;
        $bus = $busPick ? $this->departuresByCoords((float) $busPick['lat'], (float) $busPick['lng']) : null;

        return [
            'all' => $this->board($allPick, $hub, null),
            'tram' => $this->board($tramPick, $tram, 'tram'),
            'bus' => $this->board($busPick, $bus, 'bus'),
            'rail' => $this->board($allPick, $hub, 'rail'),
        ];
    }

    /**
     * Departures for a NAMED stop — used for "all"/"rail" at interchanges, where
     * the name correctly resolves to the combined station (incl. S/RE/RB).
     * `fetched_at` anchors the cached minutes so a later read shifts them to true
     * countdowns (see adjustForAge).
     *
     * @return array<string, mixed>
     */
    private function departuresByName(string $name): array
    {
        try {
            return Cache::flexible(
                'board_stop:'.$name,
                [20, 300],
                fn () => ['fetched_at' => time()] + $this->gtfs->getDepartures($name, 20),
            );
        } catch (\Throwable) {
            return ['departures' => [], 'source' => 'unavailable', 'fetched_at' => time()];
        }
    }

    /**
     * Departures for a stop resolved by its precise COORDINATES — the correct
     * StopPlace for a mode, unlike the name which collides across the tram / bus
     * / rail stops sharing an interchange's name.
     *
     * @return array<string, mixed>
     */
    private function departuresByCoords(float $lat, float $lng): array
    {
        try {
            return Cache::flexible(
                sprintf('board_geo:%.4f_%.4f', $lat, $lng),
                [20, 300],
                fn () => ['fetched_at' => time()] + $this->gtfs->getDeparturesNearby($lat, $lng, 20),
            );
        } catch (\Throwable) {
            return ['departures' => [], 'source' => 'unavailable', 'fetched_at' => time()];
        }
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
     * @param  array<string, mixed>|null  $result  prefetched departures for this stop
     * @return array<string, mixed>|null
     */
    private function board(?array $stop, ?array $result, ?string $filterType): ?array
    {
        if ($stop === null) {
            return null;
        }

        $result ??= ['departures' => [], 'source' => 'unavailable'];

        // Cologne has no separate metro: KVB Stadtbahn runs as a street tram and
        // dives underground downtown, where VRS may report it as "metro"/subway.
        // Fold both under the Tram tab so a Stadtbahn never vanishes from it.
        $allowedTypes = match ($filterType) {
            'tram' => ['tram', 'subway'],
            'bus' => ['bus'],
            'rail' => ['rail'],
            default => null,
        };

        // A departure board answers "can I catch something soon?" — within a 2h
        // horizon. But at night nothing is imminent, so if that leaves the board
        // empty, surface the next few departures however far out ("next tram
        // 03:56" at 01:30) rather than a dead-end "no live departures".
        $departures = $this->mapDepartures($result, $allowedTypes, $stop, 120);

        if ($departures === []) {
            $departures = array_slice(
                $this->mapDepartures($result, $allowedTypes, $stop, 24 * 60),
                0,
                4,
            );
        }

        return [
            'stop_name' => $stop['name'],
            'walk_min' => max(1, (int) ceil(($stop['_km'] ?? 0) / self::WALK_KMH * 60)),
            'source' => $result['source'] ?? 'gtfs',
            'fetched_at' => (int) ($result['fetched_at'] ?? time()),
            'departures' => $departures,
        ];
    }

    /**
     * Map a raw departure result into board rows, keeping only minutes within
     * $maxMin. Split out so {@see self::board()} can retry with a wide horizon
     * when the normal 2h window is empty (off-hours).
     *
     * @param  array<string, mixed>  $result
     * @param  list<string>|null  $allowedTypes
     * @param  array<string, mixed>  $stop
     * @return array<int, array<string, mixed>>
     */
    private function mapDepartures(array $result, ?array $allowedTypes, array $stop, int $maxMin): array
    {
        return collect($result['departures'] ?? [])
            ->when($allowedTypes !== null, fn ($c) => $c->filter(fn ($d) => in_array($d['type'] ?? '', $allowedTypes, true)))
            ->map(fn ($d) => [
                'line' => (string) ($d['line'] ?? '?'),
                'destination' => (string) ($d['direction'] ?? ''),
                'type' => (string) ($d['type'] ?? ''),
                'color' => $this->color($d),
                'minutes' => array_values(array_filter(
                    (array) ($d['departures'] ?? []),
                    fn ($m) => is_numeric($m) && $m >= 0 && $m <= $maxMin,
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
