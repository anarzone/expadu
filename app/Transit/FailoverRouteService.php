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
        private readonly TransitousAdapter $transitous,
        private readonly TriasAdapter $trias,
        private readonly CircuitBreaker $breaker,
        private readonly NearbyStopService $nearbyStops,
    ) {}

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 3): JourneyResult
    {
        $cacheKey = sprintf(
            'journey:%.4f,%.4f:%.4f,%.4f:%s:%d',
            $from->lat, $from->lng, $to->lat, $to->lng,
            $departAt?->format('YmdHi') ?? 'now',
            $max,
        );

        // Cache the ARRAY form, never the DTO — Redis deserialises cached
        // objects to __PHP_Incomplete_Class on a hit. Reconstruct on read.
        $cached = Cache::remember($cacheKey, 60, function () use ($from, $to, $departAt, $max) {
            foreach (['transitous' => $this->transitous, 'trias' => $this->trias] as $name => $adapter) {
                if ($this->breaker->isOpen($name)) {
                    continue;
                }

                try {
                    $result = $adapter->plan($from, $to, $departAt, $max);
                    $this->breaker->recordSuccess($name);

                    return $result->toArray();
                } catch (\Throwable $e) {
                    $this->breaker->recordFailure($name);
                    Log::warning("transit adapter {$name} failed", ['error' => $e->getMessage()]);
                }
            }

            return $this->degraded($from, $to)->toArray();
        });

        return JourneyResult::fromArray($cached);
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        $cacheKey = 'geocode:'.md5($query.($bias ? "{$bias->lat},{$bias->lng}" : ''));

        $cached = Cache::remember($cacheKey, 7 * 24 * 3600, function () use ($query, $bias) {
            if ($this->breaker->isOpen('transitous')) {
                return [];
            }

            try {
                $places = $this->transitous->geocode($query, $bias);
                $this->breaker->recordSuccess('transitous');

                return array_map(fn (Place $p) => $p->toArray(), $places);
            } catch (\Throwable $e) {
                $this->breaker->recordFailure('transitous');
                Log::warning('transitous geocode failed', ['error' => $e->getMessage()]);

                return [];
            }
        });

        return array_map(fn (array $p) => Place::fromArray($p), $cached);
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        $cacheKey = sprintf('revgeo:%.4f,%.4f', $point->lat, $point->lng);

        $cached = Cache::remember($cacheKey, 24 * 3600, function () use ($point) {
            if ($this->breaker->isOpen('transitous')) {
                return null;
            }

            try {
                $place = $this->transitous->reverseGeocode($point);
                $this->breaker->recordSuccess('transitous');

                return $place?->toArray();
            } catch (\Throwable $e) {
                $this->breaker->recordFailure('transitous');

                return null;
            }
        });

        return is_array($cached) ? Place::fromArray($cached) : null;
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
