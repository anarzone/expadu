<?php

namespace App\Transit;

use App\Services\KvbLineColors;
use App\Support\PerfLogger;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
     * Rail plus the street-level modes that reach it (tram/subway/walk) — but
     * NO bus. Used for the "rail rescue" re-plan: a competitive S-Bahn route is
     * often pruned because a bus stops a little nearer the door and so arrives
     * a minute sooner; dropping buses lets the S-Bahn + short walk surface.
     */
    private const RAIL_FIRST_MODES = 'TRAM,SUBWAY,METRO,RAIL,REGIONAL_RAIL,REGIONAL_FAST_RAIL,HIGHSPEED_RAIL,LONG_DISTANCE,NIGHT_RAIL,FERRY';

    /**
     * Skip the rail rescue on trivial hops — too short for rail to ever help,
     * regardless of a station being nearby.
     */
    private const RAIL_RESCUE_MIN_METRES = 1500;

    /**
     * A rail station within this distance of the origin or destination makes
     * the rail rescue worth a call — otherwise there's no train to catch and
     * the extra request is wasted.
     */
    private const RAIL_STOP_NEAR_METRES = 1300;

    /**
     * Per-call HTTP timeout, in seconds, for the plan endpoint. The failover
     * tightens this to fit its remaining wall-clock budget (see
     * {@see FailoverRouteService}); on its own the adapter uses a generous
     * default suited to the slower public providers.
     */
    protected float $planTimeout = 8.0;

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
    {
        // Transit itineraries first. Deliberately NO directModes on this call:
        // when MOTIS is handed a fast direct (bike) route in the same request it
        // prunes comparable transit and the transit option vanishes entirely.
        // Two separate calls keep both. (Verified against the live engine.)
        $timeExtra = $arriveBy ? ['arriveBy' => 'true'] : [];
        $itineraries = $this->fetchBucket($from, $to, $departAt, $max, $timeExtra, 'itineraries', $this->planTimeout);

        // "Show more options": force routes through the city's interchanges so
        // the list surfaces sensible-but-non-optimal combinations (e.g. change
        // at Ebertplatz onto a different line) that the Pareto set drops.
        if ($variety) {
            foreach ($this->corridorVias($from, $to) as $viaId) {
                try {
                    $itineraries = array_merge($itineraries, $this->fetchBucket(
                        $from, $to, $departAt, 3,
                        $timeExtra + ['via' => $viaId],
                        'itineraries', min($this->planTimeout, 3.0),
                    ));
                } catch (\Throwable) {
                    // Best effort — a via that yields nothing must not fail the plan.
                }
            }
        }

        // Rail rescue: when the set has no *clean* rail option (rail + short
        // walk, no bus feeder/egress), yet a station sits within walking reach
        // of either end, MOTIS has likely hidden the nicest S-Bahn route —
        // either pruned outright, or degraded to "S-Bahn -> bus -> walk" because
        // its default egress-walk cap is too short to reach the door from the
        // platform. Re-plan without buses and with a wider walk budget so the
        // "ride rail, walk the last bit" route surfaces, keeping only journeys
        // that actually ride rail — an addition to the full multi-modal set,
        // never a replacement. Best-effort: a failure leaves the set as-is.
        if (
            ! $this->hasCleanRail($itineraries)
            && $this->metresBetween($from, $to) >= self::RAIL_RESCUE_MIN_METRES
            && $this->railStationNear($from, $to)
        ) {
            try {
                $railItineraries = $this->fetchBucket(
                    $from, $to, $departAt, 3,
                    $timeExtra + [
                        'transitModes' => self::RAIL_FIRST_MODES,
                        'maxPreTransitTime' => 1200,
                        'maxPostTransitTime' => 1200,
                    ],
                    'itineraries', min($this->planTimeout, 4.0),
                );

                foreach ($railItineraries as $journey) {
                    if ($this->ridesRail($journey)) {
                        $itineraries[] = $journey;
                    }
                }
            } catch (\Throwable) {
                // The primary plan stands on its own.
            }
        }

        $journeys = $this->pruneAbsurd($itineraries, $arriveBy);

        // Direct walk + bike, fetched PER MODE as best-effort calls. A single
        // combined `WALK,BIKE` call with numItineraries=1 only ever returns the
        // fastest direct option (always bike), so the walk option silently
        // vanished — each mode needs its own call. A hiccup on one must never
        // drop transit or the other mode. Caps are generous so the sheet offers
        // all three modes for any reasonable city trip; only a truly absurd
        // option is dropped (walk past 90 min, bike past 80). The time anchor
        // ($timeExtra carries arriveBy) applies here too, so a bike option on an
        // "arrive by" search arrives by the target instead of leaving at it.
        $directCaps = ['BIKE' => 4800, 'WALK' => 5400];
        foreach ($directCaps as $directMode => $maxDirectTime) {
            try {
                $direct = $this->fetchBucket(
                    $from,
                    $to,
                    $departAt,
                    1,
                    ['directModes' => $directMode, 'maxDirectTime' => $maxDirectTime] + $timeExtra,
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
     *  - off-target trips — MOTIS pads the list with far-away options: the
     *    first trains of tomorrow (04:29 …) next to a 23:55 "leave now", or —
     *    on an "arrive by" search — journeys arriving hours before the target.
     *    Keep a 90-minute window anchored on the relevant end (earliest
     *    departure for leave-now/depart-at; LATEST arrival for arrive-by, so the
     *    options closest to the requested arrival survive), topping back up with
     *    the next-nearest options if fewer than three remain.
     *
     * @param  list<Journey>  $journeys
     * @return list<Journey>
     */
    private function pruneAbsurd(array $journeys, bool $arriveBy = false): array
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

        if ($arriveBy) {
            // Anchor on the LATEST arrival — the option closest to the requested
            // "arrive by" time — and keep the 90 minutes of arrivals before it.
            usort($sane, fn (Journey $a, Journey $b) => $b->arriveAt <=> $a->arriveAt);

            $windowStart = $sane[0]->arriveAt->subMinutes(90);
            $inWindow = array_values(array_filter($sane, fn (Journey $j) => $j->arriveAt >= $windowStart));
            $rest = array_values(array_filter($sane, fn (Journey $j) => $j->arriveAt < $windowStart));
        } else {
            // Anchor on the earliest departure — the soonest you can leave — and
            // keep the 90 minutes of departures after it.
            usort($sane, fn (Journey $a, Journey $b) => $a->departAt <=> $b->departAt);

            $windowEnd = $sane[0]->departAt->addMinutes(90);
            $inWindow = array_values(array_filter($sane, fn (Journey $j) => $j->departAt <= $windowEnd));
            $rest = array_values(array_filter($sane, fn (Journey $j) => $j->departAt > $windowEnd));
        }

        while (count($inWindow) < 3 && $rest !== []) {
            $inWindow[] = array_shift($rest);
        }

        return $inWindow;
    }

    /**
     * Cologne's main interchange stops, geocoded to MOTIS stop ids (cached),
     * used as `via` points to surface alternative line combinations.
     *
     * @return list<array{id: string, lat: float, lng: float}>
     */
    private function interchanges(): array
    {
        return Cache::remember('motis:interchanges', now()->addDays(30), function () {
            $names = [
                'Köln Ebertplatz', 'Köln Neumarkt', 'Köln Appellhofplatz',
                'Köln Friesenplatz', 'Köln Rudolfplatz', 'Köln Barbarossaplatz',
                'Köln Heumarkt', 'Köln Hauptbahnhof', 'Köln Zülpicher Platz',
                'Köln Poststr.', 'Köln Deutz',
            ];

            $out = [];
            foreach ($names as $name) {
                try {
                    $stop = collect($this->geocode($name))
                        ->first(fn (Place $p) => $p->stopId !== null);
                } catch (\Throwable) {
                    $stop = null;
                }

                if ($stop !== null && $stop->stopId !== null) {
                    $out[] = ['id' => $stop->stopId, 'lat' => $stop->point->lat, 'lng' => $stop->point->lng];
                }
            }

            return $out;
        });
    }

    /**
     * Interchange stop ids within the trip's bounding box — so a `via` detours
     * through a hub on the corridor, not clear across the city.
     *
     * @return list<string>
     */
    private function corridorVias(GeoPoint $from, GeoPoint $to): array
    {
        $minLat = min($from->lat, $to->lat) - 0.02;
        $maxLat = max($from->lat, $to->lat) + 0.02;
        $minLng = min($from->lng, $to->lng) - 0.02;
        $maxLng = max($from->lng, $to->lng) + 0.02;

        return collect($this->interchanges())
            ->filter(fn (array $ic) => $ic['lat'] >= $minLat && $ic['lat'] <= $maxLat
                && $ic['lng'] >= $minLng && $ic['lng'] <= $maxLng)
            ->pluck('id')
            ->take(6)
            ->values()
            ->all();
    }

    /**
     * True when a journey already offers a *clean* rail option: it rides rail,
     * makes at most one change, and uses no bus. A rail leg with a bus feeder or
     * egress (e.g. S19 -> bus -> walk) does NOT count — MOTIS falls back to that
     * when its default egress-walk cap can't reach the door from the platform,
     * so the nicer "ride rail, walk the last bit" route is exactly what the
     * wider-walk rescue re-plan recovers.
     *
     * @param  list<Journey>  $journeys
     */
    private function hasCleanRail(array $journeys): bool
    {
        foreach ($journeys as $journey) {
            if (! $this->ridesRail($journey) || $journey->transfers > 1) {
                continue;
            }

            $usesBus = false;

            foreach ($journey->legs as $leg) {
                if ($leg->mode === 'bus') {
                    $usesBus = true;
                    break;
                }
            }

            if (! $usesBus) {
                return true;
            }
        }

        return false;
    }

    /** True when the journey has at least one rail leg. */
    private function ridesRail(Journey $journey): bool
    {
        foreach ($journey->legs as $leg) {
            if ($leg->mode === 'rail') {
                return true;
            }
        }

        return false;
    }

    /** True when a rail station is within walking reach of either endpoint. */
    private function railStationNear(GeoPoint $from, GeoPoint $to): bool
    {
        foreach ($this->railStations() as $stop) {
            if (
                $this->metresBetween($from, new GeoPoint($stop['lat'], $stop['lng'])) <= self::RAIL_STOP_NEAR_METRES
                || $this->metresBetween($to, new GeoPoint($stop['lat'], $stop['lng'])) <= self::RAIL_STOP_NEAR_METRES
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Coordinates of every rail (route_type 2 — S-Bahn + regional) platform in
     * the loaded feed. The static set is cached for a month; the proximity test
     * then runs in memory, so the rescue gate costs no per-request query.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function railStations(): array
    {
        return Cache::remember('motis:rail-stations', now()->addDays(30), function () {
            return DB::table('gtfs_stops')
                ->whereIn('stop_id', function ($query) {
                    $query->select('st.stop_id')->distinct()
                        ->from('gtfs_stop_times as st')
                        ->whereIn('st.trip_id', function ($trips) {
                            $trips->select('t.trip_id')
                                ->from('gtfs_trips as t')
                                ->join('gtfs_routes as r', 'r.route_id', '=', 't.route_id')
                                ->where('r.route_type', 2);
                        });
                })
                ->get(['stop_lat', 'stop_lng'])
                ->map(fn ($stop) => ['lat' => (float) $stop->stop_lat, 'lng' => (float) $stop->stop_lng])
                ->all();
        });
    }

    /** Great-circle distance between two points, in metres (haversine). */
    private function metresBetween(GeoPoint $a, GeoPoint $b): float
    {
        $earth = 6371000.0;
        $dLat = deg2rad($b->lat - $a->lat);
        $dLng = deg2rad($b->lng - $a->lng);
        $h = sin($dLat / 2) ** 2
            + cos(deg2rad($a->lat)) * cos(deg2rad($b->lat)) * sin($dLng / 2) ** 2;

        return $earth * 2 * asin(min(1.0, sqrt($h)));
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

        $response = PerfLogger::measure("ext:{$this->source()}.plan", fn () => Http::baseUrl($this->baseUrl())
            ->timeout((int) ceil($timeout))
            ->connectTimeout((int) ceil(min(3.0, $timeout)))
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get($this->planPath(), $query)
            ->throw());

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

        $response = PerfLogger::measure("ext:{$this->source()}.matrix", fn () => Http::baseUrl($this->baseUrl())
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
            ->throw(), ['n' => count($destinations)]);

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

        $response = PerfLogger::measure("ext:{$this->source()}.geocode", fn () => Http::baseUrl($this->baseUrl())
            ->timeout(5)
            ->connectTimeout(3)
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/geocode', $params)
            ->throw());

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

        $routeColor = strtoupper(ltrim((string) ($rawLeg['routeColor'] ?? ''), '#'));

        // VRS uses 000000 as an "unset" sentinel for most lines (all S-Bahn,
        // many buses), which would paint them flat black — treat it as no
        // colour so the branded fallback (S-Bahn green, tram hues) wins.
        if ($routeColor !== '' && $routeColor !== '000000') {
            return '#'.$routeColor;
        }

        return KvbLineColors::for((string) ($rawLeg['routeShortName'] ?? ''), $mode);
    }

    /**
     * The stations a transit leg rides through (excluding board + get-off), in
     * order — name + arrival + coordinates. The lat/lng power live GPS
     * map-matching ("which stop am I at?") in the in-journey tracker.
     *
     * @param  array<int, array<string, mixed>>  $stops
     * @return array<int, array{name: string, arrive_at: string, arrive_time: string, lat: ?float, lng: ?float}>
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
                'lat' => isset($stop['lat']) ? (float) $stop['lat'] : null,
                'lng' => isset($stop['lon']) ? (float) $stop['lon'] : null,
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
