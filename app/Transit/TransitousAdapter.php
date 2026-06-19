<?php

namespace App\Transit;

use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Primary provider: the community-run Transitous instance of the MOTIS
 * engine (api.transitous.org). One service covers routing, geocoding and
 * reverse geocoding for all of Germany via DELFI feeds, VRS included.
 * Respect their usage policy — we cache aggressively in the failover
 * wrapper, this adapter stays cache-free.
 */
class TransitousAdapter implements RouteService
{
    /** MOTIS modes → our normalized modes. */
    private const MODE_MAP = [
        'WALK' => 'walk',
        'BIKE' => 'bike',
        'BUS' => 'bus',
        'COACH' => 'bus',
        'TRAM' => 'tram',
        'SUBWAY' => 'subway',
        'METRO' => 'subway',
        'RAIL' => 'rail',
        'HIGHSPEED_RAIL' => 'rail',
        'LONG_DISTANCE' => 'rail',
        'REGIONAL_RAIL' => 'rail',
        'REGIONAL_FAST_RAIL' => 'rail',
        'NIGHT_RAIL' => 'rail',
        'FERRY' => 'ferry',
    ];

    /**
     * Per-call HTTP timeout, in seconds, for the plan endpoint. The failover
     * tightens this to fit its remaining wall-clock budget (see
     * {@see FailoverRouteService}); on its own the adapter uses a generous
     * default suited to the slower public providers.
     */
    protected float $planTimeout = 8.0;

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 3): JourneyResult
    {
        // Transit itineraries first. Deliberately NO directModes on this call:
        // when MOTIS is handed a fast direct (bike) route in the same request it
        // prunes comparable transit and the transit option vanishes entirely.
        // Two separate calls keep both. (Verified against the live engine.)
        $journeys = $this->fetchBucket($from, $to, $departAt, $max, [], 'itineraries', $this->planTimeout);

        // Direct walk + bike as a best-effort second call — a hiccup here must
        // never drop transit. maxDirectTime is generous so a cross-town bike
        // ride still surfaces; an over-long walk is dropped by the cap. It only
        // runs after a successful (hence quick) transit call, so a tight timeout
        // keeps this supplementary lookup from ever dominating the response.
        try {
            $journeys = array_merge($journeys, $this->fetchBucket(
                $from,
                $to,
                $departAt,
                1,
                ['directModes' => 'WALK,BIKE', 'maxDirectTime' => 4800],
                'direct',
                min($this->planTimeout, 5.0),
            ));
        } catch (\Throwable) {
            // Transit-only is an acceptable result if the direct call fails.
        }

        return new JourneyResult($journeys, $this->source());
    }

    /**
     * A clone whose plan calls are bounded to roughly $seconds. Returning a
     * copy (rather than mutating) keeps the shared adapter instance — and any
     * concurrent use of it within the request — untouched. Floored so a call
     * always has a fighting chance of completing.
     */
    public function withPlanTimeout(float $seconds): static
    {
        $clone = clone $this;
        $clone->planTimeout = max(1.5, $seconds);

        return $clone;
    }

    /**
     * One MOTIS plan call, mapping the chosen response bucket to journeys.
     * MOTIS splits its answer into `itineraries` (transit) and `direct`
     * (walk/bike); each call reads exactly one so the two buckets never
     * collide. Throws on HTTP error so the caller (and failover) can react.
     *
     * @param  array<string, mixed>  $extra  extra query params (e.g. directModes)
     * @return list<Journey>
     */
    private function fetchBucket(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt, int $max, array $extra, string $bucket, float $timeout): array
    {
        $query = [
            'fromPlace' => "{$from->lat},{$from->lng}",
            'toPlace' => "{$to->lat},{$to->lng}",
            'numItineraries' => $max,
            ...$extra,
        ];

        if ($departAt !== null) {
            $query['time'] = $departAt->utc()->toIso8601ZuluString();
        }

        $response = Http::baseUrl($this->baseUrl())
            ->timeout((int) ceil($timeout))
            ->connectTimeout((int) ceil(min(3.0, $timeout)))
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get($this->planPath(), $query)
            ->throw();

        $journeys = [];
        foreach ($response->json($bucket, []) as $itinerary) {
            $journey = $this->mapItinerary($itinerary);
            if ($journey !== null) {
                $journeys[] = $journey;
            }
        }

        return $journeys;
    }

    public function travelMatrix(GeoPoint $origin, array $destinations, string $mode = 'BIKE'): array
    {
        if ($destinations === []) {
            return [];
        }

        // MOTIS one-to-many: `one`/`many` use lat;lng pairs, `many` is one
        // comma-joined list (Guzzle percent-encodes the separators). Street
        // modes only — transit isn't supported on this endpoint.
        $many = implode(',', array_map(
            fn (GeoPoint $point) => "{$point->lat};{$point->lng}",
            $destinations,
        ));

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(6)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/one-to-many', [
                'one' => "{$origin->lat};{$origin->lng}",
                'many' => $many,
                'mode' => $mode,
                'max' => 7200,
                'maxMatchingDistance' => 1500,
                'arriveBy' => 'false',
            ])
            ->throw();

        $rows = $response->json();

        // The engine returns one entry per destination, in order: {duration}
        // (seconds) when reachable, an empty object when not. A length mismatch
        // means we can't trust the alignment — signal "unavailable" wholesale.
        if (! is_array($rows) || count($rows) !== count($destinations)) {
            return array_fill(0, count($destinations), null);
        }

        return array_map(
            fn ($row) => is_array($row) && isset($row['duration'])
                ? max(1, (int) ceil(((float) $row['duration']) / 60))
                : null,
            array_values($rows),
        );
    }

    public function geocode(string $query, ?GeoPoint $bias = null): array
    {
        $params = ['text' => $query, 'language' => 'de'];
        if ($bias !== null) {
            $params['place'] = "{$bias->lat},{$bias->lng}";
        }

        $response = Http::baseUrl($this->baseUrl())
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/geocode', $params)
            ->throw();

        return collect($response->json())
            ->filter(fn ($hit) => isset($hit['lat'], $hit['lon'], $hit['name']))
            ->map(fn ($hit) => new Place(
                name: (string) $hit['name'],
                point: new GeoPoint((float) $hit['lat'], (float) $hit['lon']),
                stopId: ($hit['type'] ?? null) === 'STOP' ? ($hit['id'] ?? null) : null,
            ))
            ->values()
            ->all();
    }

    public function reverseGeocode(GeoPoint $point): ?Place
    {
        $response = Http::baseUrl($this->baseUrl())
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/reverse-geocode', [
                'place' => "{$point->lat},{$point->lng}",
            ])
            ->throw();

        $hit = collect($response->json())->first(fn ($h) => isset($h['lat'], $h['lon'], $h['name']));

        return $hit === null ? null : new Place(
            name: (string) $hit['name'],
            point: new GeoPoint((float) $hit['lat'], (float) $hit['lon']),
            municipality: $this->municipalityOf($hit['areas'] ?? []),
        );
    }

    /**
     * The OSM admin-level-6 area is the municipality (Gemeinde/Stadt, e.g.
     * "Köln") — the unit the Rheinlandtarif Preisstufe keys on.
     *
     * @param  array<int, array<string, mixed>>  $areas
     */
    private function municipalityOf(array $areas): ?string
    {
        foreach ($areas as $area) {
            if ((int) ($area['adminLevel'] ?? 0) === 6 && isset($area['name'])) {
                return (string) $area['name'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $itinerary
     */
    private function mapItinerary(array $itinerary): ?Journey
    {
        $legs = [];

        foreach ($itinerary['legs'] ?? [] as $rawLeg) {
            $from = $rawLeg['from'] ?? null;
            $to = $rawLeg['to'] ?? null;
            if (! $from || ! $to) {
                return null;
            }

            $mode = self::MODE_MAP[$rawLeg['mode'] ?? 'WALK'] ?? 'walk';

            $legs[] = new Leg(
                mode: $mode,
                from: $this->mapPlace($from),
                to: $this->mapPlace($to),
                departAt: CarbonImmutable::parse($rawLeg['startTime'])->setTimezone('Europe/Berlin'),
                arriveAt: CarbonImmutable::parse($rawLeg['endTime'])->setTimezone('Europe/Berlin'),
                durationMin: (int) ceil(($rawLeg['duration'] ?? 0) / 60),
                lineName: $rawLeg['routeShortName'] ?? null,
                headsign: $rawLeg['headsign'] ?? null,
                polyline: $rawLeg['legGeometry']['points'] ?? null,
                // Stops ridden = intermediate stops passed + the get-off stop.
                stopsCount: $mode !== 'walk' && isset($rawLeg['intermediateStops'])
                    ? count($rawLeg['intermediateStops']) + 1
                    : null,
            );
        }

        if ($legs === []) {
            return null;
        }

        return new Journey(
            legs: $legs,
            departAt: CarbonImmutable::parse($itinerary['startTime'])->setTimezone('Europe/Berlin'),
            arriveAt: CarbonImmutable::parse($itinerary['endTime'])->setTimezone('Europe/Berlin'),
            durationMin: (int) ceil(($itinerary['duration'] ?? 0) / 60),
            transfers: (int) ($itinerary['transfers'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $place
     */
    private function mapPlace(array $place): Place
    {
        $name = (string) ($place['name'] ?? '');

        return new Place(
            name: in_array($name, ['START', 'END'], true) ? '' : $name,
            point: new GeoPoint((float) ($place['lat'] ?? 0), (float) ($place['lon'] ?? 0)),
            stopId: $place['stopId'] ?? null,
        );
    }

    protected function baseUrl(): string
    {
        return rtrim((string) config('services.transitous.url', 'https://api.transitous.org'), '/');
    }

    /**
     * The plan endpoint path. Transitous runs a build that serves v3; our
     * self-hosted MOTIS (see {@see MotisAdapter}) serves v1. Geocode and
     * reverse-geocode are v1 on both.
     */
    protected function planPath(): string
    {
        return '/api/v3/plan';
    }

    /** The `source` stamped on the JourneyResult (drives the frontend path). */
    protected function source(): string
    {
        return 'transitous';
    }
}
