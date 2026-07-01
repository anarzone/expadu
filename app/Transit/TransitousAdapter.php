<?php

namespace App\Transit;

use App\Services\KvbLineColors;
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

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6): JourneyResult
    {
        // Transit itineraries first. Deliberately NO directModes on this call:
        // when MOTIS is handed a fast direct (bike) route in the same request it
        // prunes comparable transit and the transit option vanishes entirely.
        // Two separate calls keep both. (Verified against the live engine.)
        $journeys = $this->pruneAbsurd(
            $this->fetchBucket($from, $to, $departAt, $max, [], 'itineraries', $this->planTimeout),
        );

        // Direct walk + bike, fetched PER MODE as best-effort calls. A single
        // combined `WALK,BIKE` call with numItineraries=1 only ever returns the
        // fastest direct option (always bike), so the walk option silently
        // vanished — each mode needs its own call. A hiccup on one must never
        // drop transit or the other mode. Caps are generous so the sheet offers
        // all three modes for any reasonable city trip; only a truly absurd
        // option is dropped (walk past 90 min, bike past 80).
        $directCaps = ['BIKE' => 4800, 'WALK' => 5400];
        foreach ($directCaps as $directMode => $maxDirectTime) {
            try {
                $direct = $this->fetchBucket(
                    $from,
                    $to,
                    $departAt,
                    1,
                    ['directModes' => $directMode, 'maxDirectTime' => $maxDirectTime],
                    'direct',
                    min($this->planTimeout, 5.0),
                );
                // Keep only the journey that actually used this mode — a guard
                // against a provider answering a mode slot with a different one.
                $expected = strtolower($directMode);
                foreach ($direct as $journey) {
                    if ($journey->mode() === $expected) {
                        $journeys[] = $journey;
                    }
                }
            } catch (\Throwable) {
                // Transit-only (or one mode missing) is an acceptable result.
            }
        }

        return new JourneyResult($journeys, $this->source());
    }

    /**
     * Drop transit itineraries a rider would call nonsense. MOTIS keeps them
     * because they win on another pareto axis (usually one transfer fewer),
     * but the official apps filter both kinds:
     *
     *  - duration blow-ups — "23:33 → 05:28, wait out the night-service gap"
     *    (survives only within double the best duration, 40-min grace floor);
     *  - much-later departures — near midnight MOTIS pads the list with the
     *    first trains of tomorrow (04:29 …) next to a 23:55 option. Keep the
     *    window at 90 minutes after the earliest departure, topping back up
     *    with the next-departing options if fewer than three survive.
     *
     * @param  list<Journey>  $journeys
     * @return list<Journey>
     */
    private function pruneAbsurd(array $journeys): array
    {
        if (count($journeys) < 2) {
            return $journeys;
        }

        $best = min(array_map(fn (Journey $j) => $j->durationMin, $journeys));
        $cap = max($best * 2, $best + 40);

        $sane = array_values(array_filter(
            $journeys,
            fn (Journey $j) => $j->durationMin <= $cap,
        ));

        if (count($sane) < 2) {
            return $sane;
        }

        usort($sane, fn (Journey $a, Journey $b) => $a->departAt <=> $b->departAt);

        $windowEnd = $sane[0]->departAt->addMinutes(90);
        $inWindow = array_values(array_filter($sane, fn (Journey $j) => $j->departAt <= $windowEnd));
        $later = array_values(array_filter($sane, fn (Journey $j) => $j->departAt > $windowEnd));

        while (count($inWindow) < 3 && $later !== []) {
            $inWindow[] = array_shift($later);
        }

        return $inWindow;
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
            ->map(function ($hit) {
                // Address hits name the street; append the house number so
                // "Vitalisstraße 204" doesn't collapse to "Vitalisstraße".
                $name = (string) $hit['name'];
                if (($hit['type'] ?? null) === 'ADDRESS' && ! empty($hit['houseNumber']) && ! str_contains($name, (string) $hit['houseNumber'])) {
                    $name .= ' '.$hit['houseNumber'];
                }

                return new Place(
                    name: $name,
                    point: new GeoPoint((float) $hit['lat'], (float) $hit['lon']),
                    stopId: ($hit['type'] ?? null) === 'STOP' ? ($hit['id'] ?? null) : null,
                    kind: strtolower((string) ($hit['type'] ?? 'place')),
                    area: $this->areaLabel($hit['areas'] ?? []),
                );
            })
            ->values()
            ->all();
    }

    /**
     * "Bickendorf · Köln" from MOTIS area rows: the finest-grained district
     * (highest admin level) plus the default city, skipping duplicates.
     *
     * @param  array<int, array<string, mixed>>  $areas
     */
    private function areaLabel(array $areas): ?string
    {
        if ($areas === []) {
            return null;
        }

        $city = collect($areas)->firstWhere('default', true)['name'] ?? null;
        $district = collect($areas)
            ->filter(fn ($a) => ($a['adminLevel'] ?? 0) >= 9 && ($a['name'] ?? null) !== $city)
            ->sortByDesc('adminLevel')
            ->first()['name'] ?? null;

        return implode(' · ', array_filter([$district, $city])) ?: null;
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
                intermediateStops: $mode !== 'walk' && isset($rawLeg['intermediateStops'])
                    ? $this->mapIntermediateStops((array) $rawLeg['intermediateStops'])
                    : null,
                lineColor: $this->mapLineColor($rawLeg, $mode),
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
     * The badge colour for a transit leg: the feed's route colour when it
     * carries one, else the KVB brand map (Cologne is the service area).
     *
     * @param  array<string, mixed>  $rawLeg
     */
    private function mapLineColor(array $rawLeg, string $mode): ?string
    {
        if ($mode === 'walk' || $mode === 'bike') {
            return null;
        }

        $routeColor = (string) ($rawLeg['routeColor'] ?? '');
        if ($routeColor !== '') {
            return '#'.ltrim($routeColor, '#');
        }

        return KvbLineColors::for((string) ($rawLeg['routeShortName'] ?? ''), $mode);
    }

    /**
     * The stations a transit leg rides through (excluding board + get-off), in
     * order — name + arrival, powering the station-by-station journey timeline.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array{name: string, arrive_at: string, arrive_time: string}>
     */
    private function mapIntermediateStops(array $stops): array
    {
        $mapped = [];

        foreach ($stops as $stop) {
            $name = (string) ($stop['name'] ?? '');
            $arrival = $stop['arrival'] ?? null;

            if ($name === '' || $arrival === null) {
                continue;
            }

            $arriveAt = CarbonImmutable::parse($arrival)->setTimezone('Europe/Berlin');

            $mapped[] = [
                'name' => $name,
                'arrive_at' => $arriveAt->toIso8601String(),
                'arrive_time' => $arriveAt->format('H:i'),
            ];
        }

        return $mapped;
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
