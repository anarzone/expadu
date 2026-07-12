<?php

namespace App\Transit;

use App\Services\NearbyStopService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The RouteService the app actually consumes. Failure design is built-in,
 * not bolted on: Transitous fails twice → circuit opens → TRIAS answers;
 * both circuits open → degraded result (nearest-stop departures + deep
 * links to KVB/Google). Deep-linking out is legitimate — routing is
 * connective tissue, not the product.
 */
class FailoverRouteService implements RouteService
{
    public function __construct(
        private readonly MotisAdapter $motis,
        private readonly TransitousAdapter $transitous,
        private readonly TriasAdapter $trias,
        private readonly CircuitBreaker $breaker,
        private readonly NearbyStopService $nearbyStops,
    ) {}

    /**
     * NRW service-area bounds. A coordinate outside the area MOTIS was built
     * for can never produce a faithful route, so we short-circuit to degraded
     * rather than let any provider render a nonsense journey to it.
     */
    private const NRW_BOUNDS = ['minLat' => 50.0, 'maxLat' => 52.6, 'minLng' => 5.8, 'maxLng' => 9.5];

    /**
     * Total wall-clock budget for the whole provider cascade. A user tap must
     * resolve — to a real route or to the degraded board — inside this; a slow
     * chain falls through to degraded rather than spinning across every
     * provider's full timeout.
     */
    private const JOURNEY_BUDGET_SECONDS = 12.0;

    /** Don't start a provider call with less than this much budget left. */
    private const MIN_ATTEMPT_SECONDS = 2.0;

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
    {
        if (! $this->withinServiceArea($from) || ! $this->withinServiceArea($to)) {
            Log::warning('journey requested outside NRW service area', [
                'from' => "{$from->lat},{$from->lng}", 'to' => "{$to->lat},{$to->lng}",
            ]);

            return $this->degraded($from, $to);
        }

        $cacheKey = sprintf(
            'journey:%.4f,%.4f:%.4f,%.4f:%s:%d:%d:%d',
            $from->lat, $from->lng, $to->lat, $to->lng,
            $departAt?->format('YmdHi') ?? 'now',
            $max,
            $arriveBy ? 1 : 0,
            $variety ? 1 : 0,
        );

        // Cache the ARRAY form, never the DTO — Redis deserialises cached
        // objects to __PHP_Incomplete_Class on a hit. Cache is an optimisation:
        // an outage must not prevent the provider cascade from running.
        try {
            $cached = $this->readJourneyCache($cacheKey);
            if (is_array($cached)) {
                return JourneyResult::fromArray($cached);
            }
        } catch (\Throwable $exception) {
            Log::warning('journey cache read failed; continuing without cache', ['error' => $exception->getMessage()]);
        }

        $cached = $this->planWithProviders($from, $to, $departAt, $max, $arriveBy, $variety);

        try {
            $this->writeJourneyCache($cacheKey, $cached);
        } catch (\Throwable $exception) {
            Log::warning('journey cache write failed; returning uncached result', ['error' => $exception->getMessage()]);
        }

        return JourneyResult::fromArray($cached);
    }

    protected function readJourneyCache(string $key): mixed
    {
        return Cache::get($key);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function writeJourneyCache(string $key, array $value): void
    {
        Cache::put($key, $value, 60);
    }

    /**
     * @return array<string, mixed>
     */
    private function planWithProviders(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt, int $max, bool $arriveBy, bool $variety): array
    {
        $deadline = $this->now() + self::JOURNEY_BUDGET_SECONDS;

        foreach (['motis' => $this->motis, 'transitous' => $this->transitous, 'trias' => $this->trias] as $name => $adapter) {
            if ($this->breaker->isOpen($name)) {
                continue;
            }

            $remaining = $deadline - $this->now();
            if ($remaining < self::MIN_ATTEMPT_SECONDS) {
                Log::warning('journey budget exhausted, falling through to degraded', [
                    'tried_through' => $name, 'remaining' => round($remaining, 2),
                ]);
                break;
            }

            $bounded = $adapter instanceof TransitousAdapter
                ? $adapter->withPlanTimeout(min($remaining, 8.0))
                : $adapter;

            try {
                $result = $bounded->plan($from, $to, $departAt, $max, $arriveBy, $variety);
                $this->breaker->recordSuccess($name);

                return $result->toArray();
            } catch (\Throwable $exception) {
                $this->breaker->recordFailure($name);
                Log::warning("transit adapter {$name} failed", ['error' => $exception->getMessage()]);
            }
        }

        return $this->degraded($from, $to)->toArray();
    }

    /**
     * Wall clock, in fractional seconds. A seam so the budget cascade is
     * testable without real sleeps.
     */
    protected function now(): float
    {
        return microtime(true);
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        $cacheKey = 'geocode:'.md5($query.($bias ? "{$bias->lat},{$bias->lng}" : ''));

        $cached = Cache::remember($cacheKey, 7 * 24 * 3600, function () use ($query, $bias) {
            foreach (['motis' => $this->motis, 'transitous' => $this->transitous] as $name => $adapter) {
                if ($this->breaker->isOpen($name)) {
                    continue;
                }

                try {
                    $places = $adapter->geocode($query, $bias);
                    $this->breaker->recordSuccess($name);

                    return array_map(fn (Place $p) => $p->toArray(), $places);
                } catch (\Throwable $e) {
                    $this->breaker->recordFailure($name);
                    Log::warning("{$name} geocode failed", ['error' => $e->getMessage()]);
                }
            }

            return [];
        });

        return array_map(fn (array $p) => Place::fromArray($p), $cached);
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        $cacheKey = sprintf('revgeo:%.4f,%.4f', $point->lat, $point->lng);

        $cached = Cache::remember($cacheKey, 24 * 3600, function () use ($point) {
            foreach (['motis' => $this->motis, 'transitous' => $this->transitous] as $name => $adapter) {
                if ($this->breaker->isOpen($name)) {
                    continue;
                }

                try {
                    $place = $adapter->reverseGeocode($point);
                    $this->breaker->recordSuccess($name);

                    return $place?->toArray();
                } catch (\Throwable $e) {
                    $this->breaker->recordFailure($name);
                }
            }

            return null;
        });

        return is_array($cached) ? Place::fromArray($cached) : null;
    }

    public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
    {
        if ($destinations === []) {
            return [];
        }

        $nulls = array_fill(0, count($destinations), null);

        // Street one-to-many is a MOTIS-only capability and strictly
        // best-effort: outside the service area, or when MOTIS is already
        // known-down (breaker open), skip straight to the caller's fallback
        // rather than wait on a doomed call.
        if (! $this->withinServiceArea($origin) || $this->breaker->isOpen('motis')) {
            return $nulls;
        }

        $cacheKey = sprintf(
            'matrix:%s:%.4f,%.4f:%s',
            $mode,
            $origin->lat,
            $origin->lng,
            md5(implode('|', array_map(fn (GeoPoint $p) => sprintf('%.5f,%.5f', $p->lat, $p->lng), $destinations))),
        );

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            // Deliberately doesn't touch the circuit breaker — a matrix hiccup
            // must never trip the journey-planning path.
            $minutes = $this->motis->travelMatrix($origin, $destinations, $mode);
        } catch (\Throwable $e) {
            Log::warning('travel matrix failed', ['error' => $e->getMessage()]);

            return $nulls;
        }

        // Cache a useful answer only — never pin an all-null result.
        if (array_filter($minutes, fn ($m) => $m !== null) !== []) {
            Cache::put($cacheKey, $minutes, 300);
        }

        return $minutes;
    }

    private function withinServiceArea(GeoPoint $p): bool
    {
        return $p->lat >= self::NRW_BOUNDS['minLat'] && $p->lat <= self::NRW_BOUNDS['maxLat']
            && $p->lng >= self::NRW_BOUNDS['minLng'] && $p->lng <= self::NRW_BOUNDS['maxLng'];
    }

    /**
     * Last resort: simpler, more reliable calls — departures near the
     * origin from our own GTFS data, plus deep links out.
     */
    private function degraded(GeoPoint $from, GeoPoint $to): JourneyResult
    {
        $departures = [];
        $nearestStop = null;

        try {
            $board = $this->nearbyStops->getDeparturesByType($from->lat, $from->lng, null, null, null);
            $departures = array_slice($board['kvb'] ?? [], 0, 6);

            $first = $departures[0] ?? null;
            if (is_array($first) && isset($first['stop_name'])) {
                $nearestStop = [
                    'name' => $first['stop_name'],
                    'walk_min' => $first['walk_min'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            Log::warning('degraded departures failed', ['error' => $e->getMessage()]);
        }

        return new JourneyResult([], 'degraded', [
            'departures' => $departures,
            'nearest_stop' => $nearestStop,
            'deep_links' => [
                'google' => sprintf(
                    'https://www.google.com/maps/dir/?api=1&origin=%F,%F&destination=%F,%F&travelmode=transit',
                    $from->lat, $from->lng, $to->lat, $to->lng,
                ),
                'kvb' => 'https://www.kvb.koeln/fahrtinfo/',
            ],
        ]);
    }
}
