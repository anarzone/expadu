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
     * @return array{all: ?array, tram: ?array, bus: ?array}
     */
    public function boards(float $lat, float $lng): array
    {
        return Cache::remember(
            sprintf('timetable:%.3f_%.3f', $lat, $lng),
            30,
            fn () => $this->build($lat, $lng),
        );
    }

    /**
     * @return array{all: ?array, tram: ?array, bus: ?array}
     */
    private function build(float $lat, float $lng): array
    {
        $stops = $this->kvb->getStops();

        if ($stops === []) {
            return ['all' => null, 'tram' => null, 'bus' => null];
        }

        return [
            'all' => $this->board($this->nearest($stops, $lat, $lng, null), $lat, $lng, null),
            'tram' => $this->board($this->nearest($stops, $lat, $lng, 'STRAB'), $lat, $lng, 'tram'),
            'bus' => $this->board($this->nearest($stops, $lat, $lng, 'BUS'), $lat, $lng, 'bus'),
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
     * @return array<string, mixed>|null
     */
    private function board(?array $stop, float $lat, float $lng, ?string $filterType): ?array
    {
        if ($stop === null) {
            return null;
        }

        try {
            $result = $this->gtfs->getDepartures($stop['name'], 20);
        } catch (\Throwable) {
            $result = ['departures' => [], 'source' => 'unavailable'];
        }

        $departures = collect($result['departures'] ?? [])
            ->when($filterType !== null, fn ($c) => $c->filter(fn ($d) => ($d['type'] ?? null) === $filterType))
            ->map(fn ($d) => [
                'line' => (string) ($d['line'] ?? '?'),
                'destination' => (string) ($d['direction'] ?? ''),
                'type' => (string) ($d['type'] ?? ''),
                'color' => $this->color($d),
                'minutes' => array_values(array_filter(
                    (array) ($d['departures'] ?? []),
                    fn ($m) => is_numeric($m),
                )),
                'delay' => (int) ($d['delay'] ?? 0),
                'cancelled' => (bool) ($d['cancelled'] ?? false),
                'disrupted' => (bool) ($d['disrupted'] ?? false),
            ])
            ->filter(fn ($d) => $d['minutes'] !== [])
            ->values()
            ->all();

        return [
            'stop_name' => $stop['name'],
            'walk_min' => max(1, (int) ceil(($stop['_km'] ?? 0) / self::WALK_KMH * 60)),
            'source' => $result['source'] ?? 'gtfs',
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
