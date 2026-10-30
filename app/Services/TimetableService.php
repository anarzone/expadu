<?php

namespace App\Services;

use App\Transit\DepartureTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Departure boards at a point: Travel's Nearby reads one per boarding area
 * (App\Transit\NearbyStation). Departures come from VRS TRIAS real-time via
 * GtfsDepartureService, grouped per line and destination with the next and
 * following services and their delays; each keeps whether it's live,
 * scheduled or paused (stale).
 */
class TimetableService
{
    /**
     * Departures fetched per board. Twenty left a busy station's quieter lines
     * out (Neumarkt's 1, 7 and 9 behind its 3·4·16·18), and Nearby shows every
     * line with both directions.
     */
    private const DEPARTURES = 40;

    public function __construct(private readonly GtfsDepartureService $gtfs) {}

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

        return $this->adjustForAge($this->board($point, $raw));
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
     * @param  array<string, mixed>  $stop
     * @param  array<string, mixed>|null  $result  prefetched departures for this stop
     * @return array<string, mixed>
     */
    private function board(array $stop, ?array $result): array
    {
        $result ??= ['departures' => [], 'source' => 'unavailable'];

        // A departure board answers "can I catch something soon?" — within a 2h
        // horizon. But at night nothing is imminent, so if that leaves the board
        // empty, surface the next few departures however far out ("next tram
        // 03:56" at 01:30) rather than a dead-end "no live departures".
        $departures = $this->mapDepartures($result, $stop, 120);

        if ($departures === []) {
            $departures = array_slice(
                $this->mapDepartures($result, $stop, 24 * 60),
                0,
                4,
            );
        }

        return [
            'stop_name' => $stop['name'],
            'stop' => $this->stopArea($stop),
            'stops' => [$this->stopArea($stop)],
            'scope' => 'stop',
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
     * @param  array<string, mixed>  $stop
     * @return array<int, array<string, mixed>>
     */
    private function mapDepartures(array $result, array $stop, int $maxMin): array
    {
        return collect($result['departures'] ?? [])
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

                // Where today's runs really end, when the live headsign isn't it.
                $d['destination'] = $context['end'] ?? $d['destination'];

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
}
