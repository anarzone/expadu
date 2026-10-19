<?php

namespace App\Services;

use App\Transit\DepartureTime;
use Illuminate\Support\Carbon;
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

    /**
     * Departures fetched per board. Twenty left a busy station's quieter lines
     * out (Neumarkt's 1, 7 and 9 behind its 3·4·16·18), and Nearby shows every
     * line with both directions.
     */
    private const DEPARTURES = 40;

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
            sprintf('timetable:v4:%.3f_%.3f', $lat, $lng),
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
     * One boarding area's board: the departures at its point, age-adjusted.
     * A station's areas are different stops in the feed (Neumarkt's 1·7·9
     * board above ground, its 3·4·16·18 below), so Nearby reads each.
     *
     * @param  array{id?: int|string|null, name: string, lat: float, lng: float}  $point
     * @return array<string, mixed>|null
     */
    public function pointBoard(array $point): ?array
    {
        $raw = $this->departuresByCoords((float) $point['lat'], (float) $point['lng']);

        return $this->adjustForAge($this->board($point + ['_km' => 0], $raw, null));
    }

    /**
     * Fold the coord-resolved tram + bus boards into the hub board for the "All"
     * tab. A hub NAME resolves to a single StopPlace — the rail station at Köln
     * Hbf — so without this "All" hides the tram + bus platforms the mode tabs
     * surface. Dedup by line + destination + type + stop (a plain stop resolves all
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

        $inputs = collect(array_filter([$all, $tram, $bus]))->sortBy(fn ($board) => DepartureTime::parse($board['fetched_at'] ?? null)?->timestamp ?? 0)->all();
        foreach ($inputs as $board) {
            foreach ($board['departures'] ?? [] as $dep) {
                $key = ($dep['line'] ?? '').'|'.($dep['destination'] ?? '').'|'.($dep['type'] ?? '').'|'.($dep['stop_name'] ?? '');
                if (isset($seen[$key])) {
                    $index = $seen[$key];
                    $rows[$index]['minutes'] = array_values(array_unique(array_merge($rows[$index]['minutes'], $dep['minutes'])));
                    sort($rows[$index]['minutes']);
                    $rows[$index]['services'] = array_values(array_replace(array_column($rows[$index]['services'] ?? [], null, 'id'), array_column($dep['services'] ?? [], null, 'id')));

                    continue;
                }

                $seen[$key] = count($rows);
                $dep['direction'] = null;
                $rows[] = $dep;
            }
        }

        $rows = array_map(fn ($row) => ! empty($row['services']) ? $this->withExactMinutes($row) : $row, $rows);
        usort($rows, fn (array $a, array $b) => ($a['minutes'][0] ?? PHP_INT_MAX) <=> ($b['minutes'][0] ?? PHP_INT_MAX));

        $stale = collect($inputs)->contains(fn ($board) => (bool) ($board['is_stale'] ?? false));
        $all['is_stale'] = $stale;
        if ($stale) {
            $all['time_basis'] = 'stale';
        } elseif (collect($inputs)->contains(fn ($board) => ($board['time_basis'] ?? null) === 'scheduled' && ! empty($board['departures']))) {
            $all['time_basis'] = 'scheduled';
        }
        $stops = collect(array_filter([$all, $tram, $bus]))->pluck('stop')->filter()->unique(fn ($stop) => $stop['id'] ?? ($stop['name'].'|'.$stop['lat'].'|'.$stop['lng']))->values()->all();
        $all['stops'] = $stops;
        $all['scope'] = count($stops) > 1 ? 'nearby' : 'stop';
        $oldest = collect($inputs)->pluck('fetched_at')->filter()->first();
        if ($oldest !== null) {
            $all['fetched_at'] = $oldest;
        }

        return ['departures' => $rows] + $all;
    }

    /**
     * Re-anchor a cached board to "now": subtract the cache age from every
     * departure minute and drop what already left; expose observation and countdown timestamps.
     *
     * @param  array<string, mixed>|null  $board
     * @return array<string, mixed>|null
     */
    private function adjustForAge(?array $board): ?array
    {
        if ($board === null) {
            return null;
        }

        $anchor = (int) ($board['fetched_at'] ?? now()->timestamp);
        $observed = array_key_exists('observed_at', $board)
            ? DepartureTime::parse($board['observed_at']) : Carbon::createFromTimestamp($anchor);
        $elapsedMin = intdiv(max(0, now()->timestamp - $anchor), 60);
        $source = $board['source'] ?? 'unavailable';
        $liveSource = in_array($source, ['trias_rt', 'gtfs_rt'], true);
        $stale = (bool) ($board['is_stale'] ?? false) || ($liveSource && ($observed === null || now()->timestamp - $observed->timestamp > 90));
        $board['fetched_at'] = $observed?->toIso8601String();
        $board['generated_at'] = now()->toIso8601String();
        $board['is_stale'] = $stale;
        $board['time_basis'] = $source === 'unavailable' ? 'unavailable'
            : ($stale ? 'stale' : ($board['time_basis'] ?? ($liveSource ? 'realtime' : 'scheduled')));
        unset($board['observed_at']);

        $board['departures'] = collect($board['departures'])
            ->map(function (array $dep) use ($elapsedMin, $stale) {
                $dep['minutes'] = array_values(array_filter(
                    array_map(fn ($m) => $m - $elapsedMin, $dep['minutes']),
                    fn ($m) => $m >= 0,
                ));
                if (! empty($dep['services'])) {
                    $dep['services'] = array_values(array_filter(array_map(function (array $service) use ($stale) {
                        if ($stale) {
                            $service['time_basis'] = 'stale';
                        }

                        return $service;
                    }, $dep['services']), fn (array $service) => ($service['cancelled'] ?? false) || (DepartureTime::parse($service['departure_at'])?->timestamp ?? 0) >= now()->timestamp));
                    $dep = $this->withExactMinutes($dep);
                }

                return $dep;
            })
            ->filter(fn (array $dep) => $dep['minutes'] !== [] || $dep['cancelled'] || ! empty($dep['services']))
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
                'board_stop:v3:'.$name,
                [20, 300],
                fn () => $this->gtfs->getDepartures($name, self::DEPARTURES) + ['countdown_at' => now()->timestamp],
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
                sprintf('board_geo:v3:%.4f_%.4f', $lat, $lng),
                [20, 300],
                fn () => $this->gtfs->getDeparturesNearby($lat, $lng, self::DEPARTURES) + ['countdown_at' => now()->timestamp],
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
            'stop' => $this->stopArea($stop),
            'stops' => [$this->stopArea($stop)],
            'scope' => 'stop',
            'walk_min' => max(1, (int) ceil(($stop['_km'] ?? 0) / self::WALK_KMH * 60)),
            'source' => $result['source'] ?? 'gtfs',
            'fetched_at' => (int) ($result['countdown_at'] ?? $result['fetched_at'] ?? now()->timestamp),
            'observed_at' => array_key_exists('observed_at', $result) ? $result['observed_at'] : Carbon::createFromTimestamp((int) ($result['countdown_at'] ?? $result['fetched_at'] ?? now()->timestamp))->toIso8601String(),
            'is_stale' => (bool) ($result['is_stale'] ?? false),
            'time_basis' => $result['time_basis'] ?? null,
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
            ->map(function ($d) use ($maxMin, $stop, $result) {
                $mapped = [
                    'line' => (string) ($d['line'] ?? '?'),
                    'stop_name' => $result['stop_name'] ?? $stop['name'],
                    'stop' => $this->stopArea($stop),
                    'destination' => (string) ($d['direction'] ?? ''),
                    'type' => (string) ($d['type'] ?? ''),
                    'color' => $this->color($d),
                    'minutes' => array_values(array_filter(
                        (array) ($d['departures'] ?? []),
                        fn ($m) => is_numeric($m) && $m >= 0 && $m <= $maxMin,
                    )),
                    'services' => $this->servicesWithinWindow((array) ($d['services'] ?? []), $maxMin),
                    'delay' => (int) ($d['delay'] ?? 0),
                    'cancelled' => (bool) ($d['cancelled'] ?? false),
                    'disrupted' => (bool) ($d['disrupted'] ?? false),
                ];
                foreach ($mapped['services'] as &$service) {
                    $service['stop_name'] = $service['boarding_point']['name'] ?? $mapped['stop_name'];
                }
                unset($service);

                return array_key_exists('services', $d) ? $this->withExactMinutes($mapped) : $mapped;
            })
            ->filter(fn ($d) => $d['minutes'] !== [] || $d['cancelled'] || $d['services'] !== [])
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

    private function stopArea(array $stop): array
    {
        return [
            'id' => ! empty($stop['id']) ? 'kvb:'.$stop['id'] : null,
            'name' => $stop['name'],
            'lat' => isset($stop['lat']) ? (float) $stop['lat'] : null,
            'lng' => isset($stop['lng']) ? (float) $stop['lng'] : null,
            'coordinate_kind' => 'stop_area',
            'boarding_confirmed' => false,
        ];
    }

    private function withExactMinutes(array $departure): array
    {
        $departure['services'] = collect($departure['services'] ?? [])->sortBy(fn ($service) => DepartureTime::parse($service['departure_at'])?->timestamp ?? 0)->values()->all();
        $departure['minutes'] = collect($departure['services'] ?? [])
            ->filter(fn ($service) => ! ($service['cancelled'] ?? false))
            ->map(fn ($service) => max(0, (int) floor(((DepartureTime::parse($service['departure_at'])?->timestamp ?? 0) - now()->timestamp) / 60)))
            ->unique()->sort()->values()->all();
        $departure['cancelled'] = ! empty($departure['services']) && collect($departure['services'])->every(fn ($service) => (bool) ($service['cancelled'] ?? false));

        return $departure;
    }

    private function servicesWithinWindow(array $services, int $maxMinutes): array
    {
        return array_values(array_filter($services, function ($service) use ($maxMinutes): bool {
            if (! is_array($service) || ! isset($service['id'], $service['departure_at'])) {
                return false;
            }
            $at = DepartureTime::parse($service['departure_at'])?->timestamp;
            if ($at === null) {
                return false;
            }
            $seconds = $at - now()->timestamp;

            return $seconds >= 0 && $seconds <= $maxMinutes * 60;
        }));
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
