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

    /**
     * The travel-time matrix has its own circuit, opened by a single failure:
     * it is best-effort (cards fall back to estimates), callers often ask for
     * walk and bike back to back, and a matrix hiccup must never trip the
     * journey-planning circuit.
     */
    private const MATRIX_CIRCUIT = 'motis-matrix';

    /** Absolute deadline (see now()) set by withTimeBudget(); null = per-call budgets only. */
    private ?float $budgetDeadline = null;

    public function withTimeBudget(float $seconds): static
    {
        $clone = clone $this;
        $clone->budgetDeadline = $this->now() + max(0.0, $seconds);

        return $clone;
    }

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
        if ($this->budgetDeadline !== null) {
            $deadline = min($deadline, $this->budgetDeadline);
        }
        $providers = ['transitous' => $this->transitous, 'trias' => $this->trias];
        if ($departAt !== null || $arriveBy) {
            unset($providers['trias']);
        }

        if ($this->motisConfigured()) {
            $providers = ['motis' => $this->motis] + $providers;
        }

        foreach ($providers as $name => $adapter) {
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

            // Every adapter call, sub-calls included, finishes inside what is
            // left of the budget.
            $bounded = $adapter->withTimeBudget($remaining);

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

        $cached = Cache::get($cacheKey);
        if (! is_array($cached)) {
            $cached = null;

            foreach ($this->lookupProviders() as $name => $adapter) {
                if ($this->breaker->isOpen($name)) {
                    continue;
                }

                $bounded = $this->boundedForLookup($adapter);
                if ($bounded === null) {
                    break;
                }

                try {
                    $places = $bounded->geocode($query, $bias);
                    $this->breaker->recordSuccess($name);
                    $cached = array_map(fn (Place $p) => $p->toArray(), $places);
                    break;
                } catch (\Throwable $e) {
                    $this->breaker->recordFailure($name);
                    Log::warning("{$name} geocode failed", ['error' => $e->getMessage()]);
                }
            }

            // Cache only an answer a provider gave (an empty one included) —
            // never a failure, which would hide the place for a week.
            if ($cached === null) {
                return [];
            }

            Cache::put($cacheKey, $cached, 7 * 24 * 3600);
        }

        return array_map(fn (array $p) => Place::fromArray($p), $cached);
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        $cacheKey = sprintf('revgeo:%.4f,%.4f', $point->lat, $point->lng);

        $cached = Cache::remember($cacheKey, 24 * 3600, function () use ($point) {
            foreach ($this->lookupProviders() as $name => $adapter) {
                if ($this->breaker->isOpen($name)) {
                    continue;
                }

                // Out of budget: answer null (not cached — remember() treats a
                // stored null as a miss).
                $bounded = $this->boundedForLookup($adapter);
                if ($bounded === null) {
                    return null;
                }

                try {
                    $place = $bounded->reverseGeocode($point);
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

    /**
     * Geocoding providers in order: our MOTIS first when configured.
     *
     * @return array<string, TransitousAdapter>
     */
    private function lookupProviders(): array
    {
        $providers = ['transitous' => $this->transitous];

        if ($this->motisConfigured()) {
            $providers = ['motis' => $this->motis] + $providers;
        }

        return $providers;
    }

    /**
     * The adapter bound to what is left of this service's time budget, or
     * null when too little is left to start a call. Without a budget the
     * adapter keeps its own per-call timeouts.
     */
    private function boundedForLookup(TransitousAdapter $adapter): ?TransitousAdapter
    {
        if ($this->budgetDeadline === null) {
            return $adapter;
        }

        $remaining = $this->budgetDeadline - $this->now();

        return $remaining >= self::MIN_ATTEMPT_SECONDS ? $adapter->withTimeBudget($remaining) : null;
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
        if (! $this->motisConfigured()
            || ! $this->withinServiceArea($origin)
            || $this->breaker->isOpen('motis')
            || $this->breaker->isOpen(self::MATRIX_CIRCUIT)) {
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
            $minutes = $this->motis->travelMatrix($origin, $destinations, $mode);
            $this->breaker->recordSuccess(self::MATRIX_CIRCUIT);
        } catch (\Throwable $e) {
            // One failure opens the matrix circuit, so a walk call that timed
            // out spares the bike call right after it (and the next lists).
            $this->breaker->recordFailure(self::MATRIX_CIRCUIT, threshold: 1);
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

    private function motisConfigured(): bool
    {
        return filled(config('services.motis.url'));
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
