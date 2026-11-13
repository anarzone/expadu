<?php

namespace App\Transit;

use App\Services\GtfsRailStations;
use App\Services\KvbLineColors;
use App\Support\PerfLogger;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\JourneyResult;
use App\Transit\Dto\Leg;
use App\Transit\Dto\Place;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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
        'SUBURBAN' => 'rail',
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
     * Per-call HTTP timeout cap, in seconds, for the primary plan call — a
     * generous default suited to the slower public providers. A time budget
     * (see {@see withTimeBudget()}) cuts it further.
     */
    protected float $planTimeout = 8.0;

    /** Don't start an optional sub-call with less than this much budget left. */
    private const MIN_SUBCALL_SECONDS = 1.0;

    /**
     * Narrower asks beside the main plan. The engine answers with a Pareto set, so a way with a
     * change but little walking (12 then 3 to the door) or with no change but a longer walk is
     * dropped whenever another way beats it on every count, and the list showed one route at
     * ten-minute steps where Google and Apple show three. Each ask here keeps only the routes the
     * main answer doesn't already have.
     */
    private const ALTERNATIVES = [
        // A short walk to the first stop: a tram one stop rather than eleven minutes on foot to the 12.
        // The limit follows the main answer ({@see shortWalkSeconds}).
        'short_start' => ['maxPreTransitTime' => self::SHORT_WALK_SECONDS],
        // A short walk from the last stop (12 then 3 to the door). Apart from the start's: one ask for
        // both found nothing whenever home is six minutes from its stop, and returned nonsense instead.
        'short_end' => ['maxPostTransitTime' => self::SHORT_WALK_SECONDS],
        // No change ({@see directOnly}).
        'no_change' => [],
    ];

    /** The walk the short-walk asks allow at least: five minutes. */
    private const SHORT_WALK_SECONDS = 300;

    /** How much shorter than every walk of the main answer the short-walk asks look for. */
    private const SHORTER_WALK_MINUTES = 3;

    /** Runs kept of each route the alternatives add: the next one and the one after. */
    private const ALTERNATIVE_RUNS = 2;

    /** Stops of the best way's first run asked as a `via` ({@see changeStops}). */
    private const CONTINUATION_STOPS = 3;

    /** Stops of a run looked through for where to change: all of a city run. */
    private const RUN_STOPS_SCANNED = 60;

    /**
     * How much later than the best a change on the way may get there: the 12 then 18 at Ebertplatz,
     * eight minutes after the 12 then 3, is a way Google lists too (the 16 there rides as its "or").
     */
    private const CHANGE_ON_THE_WAY_SLACK_MINUTES = 10;

    /** Rides on from a change stop asked for: enough to reach past the stay's late one (the 16, then the 18). */
    private const ON_FROM_THE_CHANGE_RUNS = 6;

    /** Stops of the best way's main ride asked as a `via` to change onto it, the nearest the start. */
    private const WAYS_IN_STOPS = 3;

    /** A stop this many lines serve is where riders change (by the timetable). */
    private const INTERCHANGE_LINES = 4;

    /** A stop to change onto the main ride is no further than this from the start. */
    private const WAYS_IN_KM = 3.0;

    /** A way in starts with no longer a walk than this to its first ride. */
    private const WAYS_IN_START_WALK_MIN = 5;

    /** Another way a `via` asks for is kept when it gets there no later than the best plus this (Google's are 2–5 later). */
    private const CONTINUATION_SLACK_MINUTES = 6;

    /** Other stops to get off at, within this of the destination. */
    private const WAYS_OUT_METRES = 600;

    /** Other stops near the destination asked as a `via` to get off at. */
    private const WAYS_OUT_STOPS = 2;

    /** Absolute wall-clock deadline (microtime) set by withTimeBudget(); null = per-call caps only. */
    private ?float $deadline = null;

    public function plan(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt = null, int $max = 6, bool $arriveBy = false, bool $variety = false): JourneyResult
    {
        // Transit itineraries first. Deliberately NO directModes on this call:
        // when MOTIS is handed a fast direct (bike) route in the same request it
        // prunes comparable transit and the transit option vanishes entirely.
        // Two separate calls keep both. (Verified against the live engine.)
        $timeExtra = $arriveBy ? ['arriveBy' => 'true'] : [];
        // "Show later routes" asks for more runs of each way.
        $itineraries = $this->fetchBucket(
            $from, $to, $departAt, $variety ? $max + 6 : $max, $timeExtra, 'itineraries',
            $this->callTimeout($this->planTimeout) ?? throw new \RuntimeException('journey time budget spent'),
        );

        $itineraries = $this->withAlternatives($from, $to, $departAt, $timeExtra, $itineraries);

        // Rail rescue: when the set has no *clean* rail option (rail + short
        // walk, no bus feeder/egress), yet a station sits within walking reach
        // of either end, MOTIS has likely hidden the nicest S-Bahn route —
        // either pruned outright, or degraded to "S-Bahn -> bus -> walk" because
        // its default egress-walk cap is too short to reach the door from the
        // platform. Re-plan without buses and with a wider walk budget so the
        // "ride rail, walk the last bit" route surfaces, keeping only journeys
        // that actually ride rail — an addition to the full multi-modal set,
        // never a replacement. Best-effort: a failure leaves the set as-is.
        $railTimeout = $this->callTimeout(4.0);
        if (
            $railTimeout !== null
            && ! $this->hasCleanRail($itineraries)
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
                    'itineraries', $railTimeout,
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

        // Direct walk (and bike, once there's a bike map to show it on: the owner,
        // 3 Nov 2026), fetched PER MODE as best-effort calls. A single
        // combined `WALK,BIKE` call with numItineraries=1 only ever returns the
        // fastest direct option (always bike), so the walk option silently
        // vanished — each mode needs its own call. A hiccup on one must never
        // drop transit or the other mode. Caps are generous so the sheet offers
        // all three modes for any reasonable city trip; only a truly absurd
        // option is dropped (walk past 90 min, bike past 80). The time anchor
        // ($timeExtra carries arriveBy) applies here too, so a bike option on an
        // "arrive by" search arrives by the target instead of leaving at it.
        $directCaps = config('services.motis.bike_routes') ? ['BIKE' => 4800, 'WALK' => 5400] : ['WALK' => 5400];
        foreach ($directCaps as $directMode => $maxDirectTime) {
            $timeout = $this->callTimeout(5.0);
            if ($timeout === null) {
                break;
            }

            try {
                $direct = $this->fetchBucket(
                    $from,
                    $to,
                    $departAt,
                    1,
                    ['directModes' => $directMode, 'maxDirectTime' => $maxDirectTime] + $timeExtra,
                    'direct',
                    $timeout,
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
        // Off a line and back on it is never a way, whichever ask found it; unless it's the only one.
        $once = array_values(array_filter($journeys, fn (Journey $j) => ! $this->ridesALineTwice($j)));
        $journeys = $once === [] ? $journeys : $once;
        if (count($journeys) < 2) {
            return $journeys;
        }
        // Nor a slower ride on from the same change: the 12 to Friesenplatz then the 172, when the 3
        // from there gets to Cafe Nova five minutes sooner on less walking (Google lists no 172).
        $all = $journeys;
        $journeys = array_values(array_filter($journeys, fn (Journey $j) => ! $this->worseOnFromTheSameChange($j, $all)));

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
     * the loaded feed, kept until the next GTFS import; the proximity test
     * then runs in memory, so the rescue gate costs no per-request query.
     *
     * @return list<array{name: string, lat: float, lng: float}>
     */
    private function railStations(): array
    {
        return app(GtfsRailStations::class)->all();
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
    public function withTimeBudget(float $seconds): static
    {
        $clone = clone $this;
        $clone->deadline = $this->now() + max(0.0, $seconds);

        return $clone;
    }

    /**
     * The timeout for one call: its cap, cut to what is left of the budget.
     * Null when less than {@see MIN_SUBCALL_SECONDS} is left — too little to
     * be worth starting the call.
     */
    private function callTimeout(float $cap): ?float
    {
        if ($this->deadline === null) {
            return $cap;
        }

        $left = $this->deadline - $this->now();

        return $left >= self::MIN_SUBCALL_SECONDS ? min($cap, $left) : null;
    }

    /** Wall clock in fractional seconds; a seam for tests. */
    protected function now(): float
    {
        return microtime(true);
    }

    /**
     * The main answer plus what the narrower asks find that it lacks, all asked at once:
     *
     * - less walking and no change ({@see ALTERNATIVES}): routes the Pareto set dropped, two runs each;
     * - other ways on from the best way's first tram, as Google lists them ("12 then 13", "12 then S19",
     *   "12 then 5"): the router keeps one way per first run, so the stops that run passes nearest the
     *   destination are each asked as a `via`, and a way on the same first run with lines the list lacks,
     *   there within ten minutes of the best, is kept.
     *
     * Best effort: an ask that fails or runs out of time leaves the answer as it was.
     *
     * @param  array<string, mixed>  $timeExtra
     * @param  list<Journey>  $itineraries
     * @return list<Journey>
     */
    private function withAlternatives(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt, array $timeExtra, array $itineraries): array
    {
        [$best, $vias] = $this->viaAsks($from, $to, $itineraries);
        $timeout = $this->callTimeout(5.0);
        if ($timeout === null) {
            return $itineraries;
        }

        $asks = self::ALTERNATIVES;
        // Measured on the ways the list keeps: at 18:30 the main answer's 7 from Porz Markt leaves at
        // 20:25, past the list's window, and its 10-minute walk hid the 18:39 one.
        $shown = $this->pruneAbsurd($itineraries, isset($timeExtra['arriveBy']));
        $asks['short_start'] = ['maxPreTransitTime' => $this->shortWalkSeconds($shown, start: true)];
        $asks['short_end'] = ['maxPostTransitTime' => $this->shortWalkSeconds($shown, start: false)];
        $asks['no_change'] = $this->directOnly();
        $asks += array_map(fn (array $via) => $via['ask'], $vias);

        try {
            $responses = PerfLogger::measure("ext:{$this->source()}.alternatives", fn () => Http::pool(fn (Pool $pool) => array_map(
                fn (string $name, array $extra) => $pool->as($name)
                    ->baseUrl($this->baseUrl())
                    ->timeout($timeout)
                    ->connectTimeout(min(3.0, $timeout))
                    ->withHeaders(['User-Agent' => 'expadu.com'])
                    ->get($this->planPath(), isset($vias[$name]['at'])
                        // On from a change stop, from when the first run gets there (see withoutTheStay).
                        ? $this->planQuery($from, $to, $vias[$name]['at'], self::ON_FROM_THE_CHANGE_RUNS, $extra)
                        : $this->planQuery($from, $to, $departAt, isset($extra['via']) ? 2 : 4, $timeExtra + $extra)),
                array_keys($asks),
                array_values($asks),
            )));
        } catch (\Throwable) {
            return $itineraries;
        }

        // Known: what the list keeps, so the 18:39 7 isn't taken for the 20:25 one the window drops.
        $known = array_fill_keys(array_map($this->route(...), $shown), true);
        $added = [];
        $onward = [];
        $stayed = [];
        $onFrom = [];
        $bestAt = $best?->arriveAt;
        foreach ($responses as $name => $response) {
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $via = $vias[$name] ?? null;
            foreach ($response->json('itineraries', []) as $itinerary) {
                $journey = $this->mapItinerary($itinerary);
                if ($journey !== null && $via !== null && $via['kind'] === 'next') {
                    $onFrom[$via['stop']][] = $journey;

                    continue;
                }
                if ($journey === null || isset($known[$this->route($journey)])) {
                    continue;
                }
                if ($via === null) {
                    $added[$this->route($journey)][$journey->departAt->getTimestamp()] = $journey;
                } elseif ($journey->arriveAt->lte($bestAt?->addMinutes($via['slack'] ?? self::CONTINUATION_SLACK_MINUTES)) && $this->keeps($via, $journey)) {
                    $onward[$this->route($journey)] ??= $journey;
                    if ($via['kind'] === 'on' && isset($via['ask']['viaMinimumStay'])) {
                        $stayed[$this->route($journey)] = $via['stop'];
                    }
                }
            }
        }
        $onward = $this->withoutTheStay($onward, $stayed, $onFrom);

        $main = $itineraries;
        foreach ($added as $route => $runs) {
            ksort($runs);
            $runs = array_values($runs);
            if ($this->onlyAddsARide($runs[0], $main)) {
                unset($added[$route]);

                continue;
            }
            array_push($itineraries, ...array_slice($runs, 0, self::ALTERNATIVE_RUNS));
        }
        foreach ($onward as $route => $journey) {
            if (! isset($added[$route])) {
                $itineraries[] = $journey;
            }
        }

        return $itineraries;
    }

    /**
     * The `via` asks for other ways around the best one (soonest there, with a ride), as Google and
     * Apple list them; the router keeps one way per run, so these are asked for by name:
     *
     * - ways on (when the best way changes): the stops its first run passes where a line serving the
     *   destination stops too ({@see changeStops}); kept when they ride that same first run ("12 then
     *   13", "12 then S19", "12 then 16" at Ebertplatz);
     * - ways in: the stops its main ride (the longest) passes after its boarding stop, nearest the
     *   start and within {@see WAYS_IN_KM}, each with a minute's stay so the way changes there; kept
     *   when another ride brings them onto that line there ("16 / 18 to Ebertplatz, then the 12", not
     *   an eleven-minute walk to Rudolfplatz), and the stop the soonest way walks more than five
     *   minutes to, kept when it walks less (the 7 or 9 one stop to Rudolfplatz, then the 12);
     * - ways out: the other stops near the destination, each with a minute's stay so the way gets off
     *   there; kept when it needs no more rides than the best (the 1/7/9 to Deutzer Freiheit beside
     *   the 3/4 to Suevenstr.).
     *
     * @param  list<Journey>  $itineraries
     * @return array{0: Journey|null, 1: array<string, array{ask: array<string, mixed>, kind: string, run: string|null, line: string|null, stop: string, rides?: int, walk?: int, slack?: int, at?: CarbonImmutable}>}
     */
    private function viaAsks(GeoPoint $from, GeoPoint $to, array $itineraries): array
    {
        $best = collect($itineraries)
            ->filter(fn (Journey $journey) => $this->firstRide($journey)?->tripId !== null)
            ->sortBy(fn (Journey $journey) => $journey->arriveAt->getTimestamp())
            ->first();
        if ($best === null) {
            return [null, []];
        }

        $vias = [];
        $first = $this->firstRide($best);
        $aroundTo = $this->stopsNear($to, self::WAYS_OUT_METRES);
        if ($best->transfers >= 1) {
            foreach ($this->changeStops($first, $to, $aroundTo) as $n => $stop) {
                $vias["on{$n}"] = [
                    'ask' => ['via' => $stop['id']] + ($stop['change'] ? ['viaMinimumStay' => 1] : []),
                    'kind' => 'on', 'run' => $first->tripId, 'line' => null, 'stop' => $stop['name'],
                    'slack' => $stop['change'] ? self::CHANGE_ON_THE_WAY_SLACK_MINUTES : self::CONTINUATION_SLACK_MINUTES,
                ];
                // And on from there, from when the first run gets there: asked beside the rest, not
                // after them, so a slow answer elsewhere can't leave the stay's late ride standing.
                $there = collect($first->intermediateStops ?? [])->firstWhere('name', $stop['name'])['arrive_at'] ?? null;
                if ($stop['change'] && $there !== null) {
                    $vias["next{$n}"] = [
                        'ask' => ['fromPlace' => $stop['id'], ...$this->directOnly()],
                        'kind' => 'next', 'run' => null, 'line' => null, 'stop' => $stop['name'], 'at' => CarbonImmutable::parse($there),
                    ];
                }
            }
        }

        $main = collect($best->legs)->filter(fn (Leg $leg) => $leg->isTransit())->sortByDesc(fn (Leg $leg) => $leg->durationMin)->first();
        $near = fn (array $stop) => $this->metresBetween(new GeoPoint($stop['lat'], $stop['lng']), $from) <= self::WAYS_IN_KM * 1000;
        $candidates = $this->runStopsAfter($main, $from, self::WAYS_IN_STOPS * 3, beforeOff: true, only: $near);
        // Where riders change: stops many lines serve (an unknown count is asked, the timetable may be missing).
        $lines = app(StopLines::class)->count(array_column($candidates, 'name'));
        $interchanges = array_values(array_filter($candidates, fn (array $stop) => ($lines[$stop['name']] ?? self::INTERCHANGE_LINES) >= self::INTERCHANGE_LINES));
        foreach (array_slice($interchanges, 0, self::WAYS_IN_STOPS) as $n => $stop) {
            $vias["in{$n}"] = ['ask' => ['via' => $stop['id'], 'viaMinimumStay' => 1], 'kind' => 'in', 'run' => null, 'line' => $main->lineName, 'stop' => $stop['name']];
        }

        // A ride to the stop a way walks long to (the 7 or 9 one stop to Rudolfplatz for the 12, which
        // Google lists first, not ten minutes on foot); kept as a way in that walks less.
        $walked = collect($itineraries)
            ->filter(fn (Journey $journey) => ($journey->legs[0] ?? null)?->mode === 'walk'
                && $journey->legs[0]->durationMin > self::WAYS_IN_START_WALK_MIN
                && $this->firstRide($journey)?->from->stopId !== null)
            ->sortBy(fn (Journey $journey) => $journey->arriveAt->getTimestamp())
            ->first();
        $board = $walked === null ? null : $this->firstRide($walked);
        if ($board !== null && ! in_array($board->from->name, array_column($vias, 'stop'), true)) {
            // A short walk to the first stop, or the router walks to the via and stays a minute.
            $ask = ['via' => $board->from->stopId, 'viaMinimumStay' => 1, 'maxPreTransitTime' => self::WAYS_IN_START_WALK_MIN * 60];
            $vias['board'] = ['ask' => $ask, 'kind' => 'in', 'run' => null, 'line' => $board->lineName, 'stop' => $board->from->name, 'walk' => $this->walkMinutes($walked)];
        }

        // Ways out: the other stops near the destination, getting off there (the 1/7/9 to Deutzer
        // Freiheit as well as the 3/4 to Suevenstr.); kept when no more changes get them there.
        $off = collect($best->legs)->filter(fn (Leg $leg) => $leg->isTransit())->last();
        $nearby = collect($aroundTo)
            ->reject(fn (array $stop) => $stop['name'] === $off?->to->name)
            ->sortBy(fn (array $stop) => $this->metresBetween(new GeoPoint($stop['lat'], $stop['lng']), $to))
            ->take(self::WAYS_OUT_STOPS)
            ->values();
        foreach ($nearby as $n => $stop) {
            $vias["out{$n}"] = ['ask' => ['via' => $stop['id'], 'viaMinimumStay' => 1], 'kind' => 'out', 'run' => null, 'line' => null, 'stop' => $stop['name'], 'rides' => count(array_filter($best->legs, fn (Leg $leg) => $leg->isTransit()))];
        }

        return [$best, $vias];
    }

    /**
     * A change on the way is asked with a minute's stay, so the walk across starts a minute late: the
     * 12 reaches Ebertplatz at 08:30, the walk across starts 08:31 and misses the 16 at 08:36 that
     * Google takes. Beside it, the rides on from that stop were asked from when the 12 gets there; the
     * first that leaves once the walk across is done, and gets there sooner, replaces the rest of the
     * way.
     *
     * @param  array<string, Journey>  $onward  by route
     * @param  array<string, string>  $stayed  route => the stop its change was asked at
     * @param  array<string, list<Journey>>  $onFrom  stop => the ways on from it
     * @return array<string, Journey>
     */
    private function withoutTheStay(array $onward, array $stayed, array $onFrom): array
    {
        foreach ($stayed as $route => $stop) {
            $journey = $onward[$route] ?? null;
            $legs = $journey?->legs ?? [];
            $off = null;
            foreach ($legs as $i => $leg) {
                if ($leg->isTransit() && $leg->to->name === $stop) {
                    $off = $i;
                    break;
                }
            }
            $walk = $off === null ? null : ($legs[$off + 1] ?? null);
            if ($walk?->mode !== 'walk' || ! ($legs[$off + 2] ?? null)?->isTransit()) {
                continue;
            }
            $ready = $legs[$off]->arriveAt->addMinutes($walk->durationMin);

            $sooner = null;
            foreach ($onFrom[$stop] ?? [] as $on) {
                $rest = array_values(array_filter(
                    $on->legs,
                    fn (Leg $leg, int $k) => ! ($k === 0 && $leg->mode === 'walk' && $leg->durationMin === 0),
                    ARRAY_FILTER_USE_BOTH,
                ));
                if ($rest === [] || ! $rest[0]->isTransit() || $rest[0]->lineName === $legs[$off]->lineName
                    || $rest[0]->departAt->lt($ready) || $on->arriveAt->gte($sooner?->arriveAt ?? $journey->arriveAt)) {
                    continue;
                }
                $sooner = new Journey($rest, $on->departAt, $on->arriveAt, 0, 0);
            }
            if ($sooner === null) {
                continue;
            }

            $across = new Leg(
                mode: 'walk', from: $walk->from, to: $walk->to, departAt: $legs[$off]->arriveAt, arriveAt: $ready,
                durationMin: $walk->durationMin, polyline: $walk->polyline, distanceM: $walk->distanceM, steps: $walk->steps,
            );
            $retimed = new Journey(
                legs: [...array_slice($legs, 0, $off + 1), $across, ...$sooner->legs],
                departAt: $journey->departAt,
                arriveAt: $sooner->arriveAt,
                durationMin: (int) round(($sooner->arriveAt->getTimestamp() - $journey->departAt->getTimestamp()) / 60),
                transfers: $journey->transfers,
            );
            unset($onward[$route]);
            $onward[$this->route($retimed)] ??= $retimed;
        }

        return $onward;
    }

    /**
     * Where the best way's first run is asked to change, for ways on: the stops it passes where a
     * line that serves the stops around the destination stops too; those on the way, before the best
     * way gets off, first; then the nearest the destination. The 12 from Merkenich passes Rudolfplatz
     * and Hansaring nearer Cafe Nova, but only at Ebertplatz do the 16 and 18 to Appellhofplatz stop
     * (Google's "12 then 16"). Without the timetable, or with no such stop: the nearest stops.
     *
     * @param  list<array{name: string, lat: float, lng: float, id: string}>  $aroundTo
     * @return list<array{name: string, lat: float, lng: float, id: string, past: bool}>
     */
    private function changeStops(Leg $first, GeoPoint $to, array $aroundTo): array
    {
        $passed = $this->runStopsAfter($first, $to, self::RUN_STOPS_SCANNED, beforeOff: false);
        $lines = app(StopLines::class)->lines([...array_column($passed, 'name'), ...array_column($aroundTo, 'name')]);
        $reach = array_diff(array_merge(...array_map(fn (array $stop) => $lines[$stop['name']] ?? [], $aroundTo)), [$first->lineName]);
        $changes = array_values(array_filter($passed, fn (array $stop) => array_intersect($lines[$stop['name']] ?? [], $reach) !== []));
        if ($changes === []) {
            return array_map(fn (array $stop) => $stop + ['change' => false], array_slice($passed, 0, self::CONTINUATION_STOPS));
        }
        // Stable: on the way first, each part still nearest the destination first.
        usort($changes, fn (array $a, array $b) => $a['past'] <=> $b['past']);

        // A change stop on the way is asked with a minute's stay: without one the router rides
        // through it on the same tram and answers with the best way again.
        return array_map(fn (array $stop) => $stop + ['change' => ! $stop['past']], array_slice($changes, 0, self::CONTINUATION_STOPS));
    }

    /**
     * The stops a ride's run passes after it boards, nearest a point: up to where the way gets off
     * ($beforeOff), else all the way on but that stop. `past`: after the stop the way gets off.
     *
     * @param  (callable(array{name: string, lat: float, lng: float, id: string|null}): bool)|null  $only
     * @return list<array{name: string, lat: float, lng: float, id: string, past: bool}>
     */
    private function runStopsAfter(?Leg $ride, GeoPoint $nearest, int $take, bool $beforeOff, ?callable $only = null): array
    {
        if ($ride?->tripId === null || $this->callTimeout(2.0) === null) {
            return [];
        }
        try {
            $run = $this->withTimeBudget(min(2.0, $this->callTimeout(2.0) ?? 0.0))->tripPath($ride->tripId);
        } catch (\Throwable) {
            return [];
        }

        $boarded = false;
        $past = false;
        $stops = [];
        foreach ($run['stops'] ?? [] as $stop) {
            if (! $boarded) {
                $boarded = $stop['name'] === $ride->from->name;

                continue;
            }
            if ($stop['name'] === $ride->to->name) {
                if ($beforeOff) {
                    break;
                }
                $past = true;

                continue;
            }
            if ($stop['id'] !== null && ($only === null || $only($stop))) {
                $stops[$stop['name']] = $stop + ['past' => $past];
            }
        }

        return collect($stops)
            ->sortBy(fn (array $stop) => $this->metresBetween(new GeoPoint($stop['lat'], $stop['lng']), $nearest))
            ->take($take)
            ->values()
            ->all();
    }

    /**
     * Whether a way a `via` ask found is the alternative it was asked for.
     *
     * @param  array{kind: string, run: string|null, line: string|null, stop: string, rides?: int, walk?: int, slack?: int}  $via
     */
    private function keeps(array $via, Journey $journey): bool
    {
        if ($via['kind'] === 'on') {
            return $this->firstRide($journey)?->tripId === $via['run'];
        }
        if ($via['kind'] === 'out') {
            $rides = array_values(array_filter($journey->legs, fn (Leg $leg) => $leg->isTransit()));

            return $rides !== [] && end($rides)->to->name === $via['stop'] && count($rides) <= $via['rides'];
        }

        // Ways in: one ride from near the start brings them to the stop, and they change onto the main
        // line there. Not a long walk to another ride first, nor a second change to catch the run that
        // passed where they already were (7 to Rudolfplatz, 15 a stop, then the 12 they could have taken).
        $rides = array_values(array_filter($journey->legs, fn (Leg $leg) => $leg->isTransit()));
        $start = $journey->legs[0] ?? null;

        // Nor riding away to catch the main line back through where they started (3 to Poststr., 4 back).
        $passes = array_column($rides[1]->intermediateStops ?? [], 'name');

        return count($rides) >= 2
            && $rides[1]->lineName === $via['line'] && $rides[1]->from->name === $via['stop']
            && ! in_array($rides[0]->from->name, $passes, true)
            && ($start === null || $start->isTransit() || $start->durationMin <= self::WAYS_IN_START_WALK_MIN)
            // A ride in place of a walk: only when it walks less than the way that walked there.
            && (! isset($via['walk']) || $this->walkMinutes($journey) < $via['walk']);
    }

    /**
     * The stops around a point, by the engine's own map of them (ids it routes by). Best effort.
     *
     * @return list<array{name: string, lat: float, lng: float, id: string}>
     */
    private function stopsNear(GeoPoint $point, int $metres): array
    {
        $timeout = $this->callTimeout(2.0);
        if ($timeout === null) {
            return [];
        }
        $dLat = $metres / 111_320;
        $dLng = $metres / (111_320 * cos(deg2rad($point->lat)));
        try {
            $response = PerfLogger::measure("ext:{$this->source()}.stops", fn () => Http::baseUrl($this->baseUrl())
                ->timeout($timeout)
                ->connectTimeout(min(2.0, $timeout))
                ->withHeaders(['User-Agent' => 'expadu.com'])
                ->get('/api/v1/map/stops', [
                    'min' => ($point->lat - $dLat).','.($point->lng - $dLng),
                    'max' => ($point->lat + $dLat).','.($point->lng + $dLng),
                ])
                ->throw());
        } catch (\Throwable) {
            return [];
        }

        $stops = [];
        foreach ((array) $response->json() as $stop) {
            if (isset($stop['name'], $stop['stopId'], $stop['lat'], $stop['lon'])) {
                $stops[$stop['name']] ??= ['name' => (string) $stop['name'], 'lat' => (float) $stop['lat'], 'lng' => (float) $stop['lon'], 'id' => (string) $stop['stopId']];
            }
        }

        return array_values($stops);
    }

    /**
     * Another way rides the same first run, gets off it at the same stop, and from there gets there
     * no later on no more walking and no more changes, and is better at one of them. A change somewhere
     * else is a different way (the 12 then 16 at Ebertplatz stays beside the 12 then 3).
     *
     * @param  list<Journey>  $journeys
     */
    private function worseOnFromTheSameChange(Journey $journey, array $journeys): bool
    {
        $rides = array_values(array_filter($journey->legs, fn (Leg $leg) => $leg->isTransit()));
        if (count($rides) < 2 || $rides[0]->tripId === null) {
            return false;
        }
        foreach ($journeys as $other) {
            $theirs = array_values(array_filter($other->legs, fn (Leg $leg) => $leg->isTransit()));
            if ($other === $journey || count($theirs) < 2 || $other->lines() === $journey->lines()
                || $theirs[0]->tripId !== $rides[0]->tripId || $theirs[0]->to->name !== $rides[0]->to->name) {
                continue;
            }
            $walk = [$this->walkMinutes($other), $this->walkMinutes($journey)];
            if ($other->arriveAt->lte($journey->arriveAt) && $walk[0] <= $walk[1] && $other->transfers <= $journey->transfers
                && ($other->arriveAt->lt($journey->arriveAt) || $walk[0] < $walk[1] || $other->transfers < $journey->transfers)) {
                return true;
            }
        }

        return false;
    }

    /** Off a line and back on it (the 4 out to Koelnmesse and back, the 12 to its end and back a stop). */
    private function ridesALineTwice(Journey $journey): bool
    {
        $lines = $journey->lines();

        return count($lines) !== count(array_unique($lines));
    }

    /**
     * The walk a short-walk ask allows, in seconds: five minutes, or, when the simplest ways of the main
     * answer (fewest rides) all walk longer to their first stop (or from their last), three minutes
     * less than the shortest of those walks. From the Rhine in Porz the S12 and RE9 walk 14 minutes to
     * Bf Porz, and the 7 from Porz Markt, 10 minutes away, is the way Google lists third. A way with a
     * bus to the station doesn't count: it is a change more, not a closer start.
     *
     * @param  list<Journey>  $itineraries
     */
    private function shortWalkSeconds(array $itineraries, bool $start): int
    {
        $walks = [];
        foreach ($itineraries as $journey) {
            $rides = count($journey->lines());
            if ($rides === 0) {
                continue;
            }
            $leg = $start ? ($journey->legs[0] ?? null) : ($journey->legs[array_key_last($journey->legs)] ?? null);
            $walks[$rides][] = $leg !== null && $leg->mode === 'walk' ? $leg->durationMin : 0;
        }
        if ($walks === []) {
            return self::SHORT_WALK_SECONDS;
        }

        return max(self::SHORT_WALK_SECONDS, (min($walks[min(array_keys($walks))]) - self::SHORTER_WALK_MINUTES) * 60);
    }

    /**
     * A way the main answer has with a ride added before or after it (the 162 to Bf Porz for the RE9,
     * the 101 from Alter Militärring after the 1) that walks no less and gets there no sooner than the
     * main answer's soonest: a change more for nothing.
     *
     * @param  list<Journey>  $itineraries
     */
    private function onlyAddsARide(Journey $journey, array $itineraries): bool
    {
        $rides = array_filter($itineraries, fn (Journey $known) => $known->lines() !== []);
        if ($rides === [] || $journey->arriveAt->lt(min(array_map(fn (Journey $known) => $known->arriveAt, $rides)))) {
            return false;
        }
        $lines = $journey->lines();
        foreach ($rides as $known) {
            $knownLines = $known->lines();
            if (count($knownLines) < count($lines) && array_diff($knownLines, $lines) === []
                && $this->walkMinutes($journey) >= $this->walkMinutes($known)) {
                return true;
            }
        }

        return false;
    }

    private function walkMinutes(Journey $journey): int
    {
        return array_sum(array_map(fn (Leg $leg) => $leg->mode === 'walk' ? $leg->durationMin : 0, $journey->legs));
    }

    private function firstRide(Journey $journey): ?Leg
    {
        foreach ($journey->legs as $leg) {
            if ($leg->isTransit()) {
                return $leg;
            }
        }

        return null;
    }

    /**
     * Asking for ways without a change. The plan API v3 (Transitous) counts changes: 0. Our MOTIS's
     * v1 counts rides ({@see MotisAdapter}), and answered 0 with nothing at all, so the lines beside a
     * ride and the tight changes were silently empty on production.
     *
     * @return array<string, int>
     */
    protected function directOnly(): array
    {
        return ['maxTransfers' => 0];
    }

    /** A route as a rider tells routes apart: its lines in order ("12>3"), or how it goes when it rides none. */
    private function route(Journey $journey): string
    {
        return implode('>', $journey->lines()) ?: $journey->mode();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function planQuery(GeoPoint $from, GeoPoint $to, ?CarbonImmutable $departAt, int $max, array $extra): array
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

        return $query;
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
        $query = $this->planQuery($from, $to, $departAt, $max, $extra);

        $response = PerfLogger::measure("ext:{$this->source()}.plan", fn () => Http::baseUrl($this->baseUrl())
            ->timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
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

        $timeout = $this->callTimeout(5.0) ?? throw new \RuntimeException('geocode time budget spent');

        $response = PerfLogger::measure("ext:{$this->source()}.geocode", fn () => Http::baseUrl($this->baseUrl())
            ->timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
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
        $timeout = $this->callTimeout(5.0) ?? throw new \RuntimeException('reverse geocode time budget spent');

        $response = Http::baseUrl($this->baseUrl())
            ->timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
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

            // A ride the feed can't name (mode OTHER, line "?": a run whose route data is missing) can't be
            // shown or taken as told, and read as a walk it made "walk › walk › walk › 12, 1 change".
            $mode = self::MODE_MAP[$rawLeg['mode'] ?? 'WALK'] ?? null;
            $line = trim((string) ($rawLeg['routeShortName'] ?? ''));
            if ($mode === null || (! in_array($mode, ['walk', 'bike'], true) && in_array($line, ['', '?'], true))) {
                return null;
            }
            $departAt = CarbonImmutable::parse($rawLeg['startTime'])->setTimezone('Europe/Berlin');
            $arriveAt = CarbonImmutable::parse($rawLeg['endTime'])->setTimezone('Europe/Berlin');
            $scheduledDepartAt = $this->scheduledTime($rawLeg['scheduledStartTime'] ?? null);
            $realtime = ($rawLeg['realTime'] ?? false) === true;
            if (in_array($mode, ['walk', 'bike'], true)) {
                $rawLeg = $this->forwards($rawLeg, $from, $to);
            }

            $legs[] = new Leg(
                mode: $mode,
                from: $this->mapPlace($from),
                to: $this->mapPlace($to),
                departAt: $departAt,
                arriveAt: $arriveAt,
                durationMin: (int) ceil(($rawLeg['duration'] ?? 0) / 60),
                lineName: $rawLeg['routeShortName'] ?? null,
                headsign: $rawLeg['headsign'] ?? null,
                // One precision for every map (MOTIS's 7); Transitous's v3 sends 6.
                polyline: isset($rawLeg['legGeometry']['points'])
                    ? Polyline::atPrecision((string) $rawLeg['legGeometry']['points'], (int) ($rawLeg['legGeometry']['precision'] ?? Polyline::PRECISION))
                    : null,
                // Stops ridden = intermediate stops passed + the get-off stop.
                stopsCount: $mode !== 'walk' && isset($rawLeg['intermediateStops'])
                    ? count($rawLeg['intermediateStops']) + 1
                    : null,
                intermediateStops: $mode !== 'walk' && isset($rawLeg['intermediateStops'])
                    ? $this->mapIntermediateStops((array) $rawLeg['intermediateStops'])
                    : null,
                lineColor: $this->mapLineColor($rawLeg, $mode),
                scheduledDepartAt: $scheduledDepartAt,
                scheduledArriveAt: $this->scheduledTime($rawLeg['scheduledEndTime'] ?? null),
                // Only a live-fed leg has a real delay; for a timetable-only
                // leg "on time" and "unknown" look identical, so say unknown.
                delayMin: $realtime && $scheduledDepartAt !== null
                    ? (int) round(($departAt->getTimestamp() - $scheduledDepartAt->getTimestamp()) / 60)
                    : null,
                realtime: $realtime,
                cancelled: ($rawLeg['cancelled'] ?? false) === true,
                tripId: isset($rawLeg['tripId']) ? (string) $rawLeg['tripId'] : null,
                distanceM: $mode === 'walk' && isset($rawLeg['distance']) ? (int) round((float) $rawLeg['distance']) : null,
                steps: in_array($mode, ['walk', 'bike'], true) ? $this->mapSteps((array) ($rawLeg['steps'] ?? [])) : null,
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

    private function scheduledTime(mixed $time): ?CarbonImmutable
    {
        return is_string($time) && $time !== ''
            ? CarbonImmutable::parse($time)->setTimezone('Europe/Berlin')
            : null;
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
     * Every ride straight from one stop to another, for several stretches at once: the lines that
     * serve each stretch, each run with when it leaves and gets there, from a time on for an hour.
     * The router's own answer keeps only the run that makes a connection; a rider wants the line
     * coming now too, and the next one should the change be missed. A stretch that fails or runs
     * out of time comes back empty.
     *
     * @param  list<array{from: GeoPoint, to: GeoPoint, at: CarbonImmutable}>  $stretches
     * @return list<list<array{line: string, headsign: string|null, color: string|null, depart_at: string, arrive_at: string, cancelled: bool}>>
     */
    public function directRides(array $stretches): array
    {
        $timeout = $this->callTimeout(4.0);
        if ($timeout === null || $stretches === []) {
            return array_map(fn () => [], $stretches);
        }

        try {
            // Answers come keyed by ask, in the order they finish: read back by key.
            $responses = PerfLogger::measure("ext:{$this->source()}.direct", fn () => Http::pool(fn (Pool $pool) => array_map(
                fn (int $n, array $stretch) => $pool->as("stretch{$n}")->baseUrl($this->baseUrl())
                    ->timeout($timeout)
                    ->connectTimeout(min(3.0, $timeout))
                    ->withHeaders(['User-Agent' => 'expadu.com'])
                    ->get($this->planPath(), $this->planQuery($stretch['from'], $stretch['to'], $stretch['at'], 12, [
                        ...$this->directOnly(), 'maxPreTransitTime' => 240, 'maxPostTransitTime' => 240, 'searchWindow' => 3600,
                    ])),
                array_keys(array_values($stretches)),
                array_values($stretches),
            )));
        } catch (\Throwable) {
            return array_map(fn () => [], $stretches);
        }

        return array_map(function (int $n) use ($responses) {
            $response = $responses["stretch{$n}"] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                return [];
            }
            $rides = [];
            foreach ($response->json('itineraries', []) as $itinerary) {
                $legs = array_values(array_filter($itinerary['legs'] ?? [], fn (array $leg) => ($leg['mode'] ?? 'WALK') !== 'WALK'));
                if (count($legs) !== 1 || ! isset($legs[0]['startTime'], $legs[0]['endTime'])) {
                    continue;
                }
                $line = trim((string) ($legs[0]['routeShortName'] ?? ''));
                if (in_array($line, ['', '?'], true) || ! isset(self::MODE_MAP[$legs[0]['mode'] ?? ''])) {
                    continue;
                }
                $rides[] = [
                    'line' => $line,
                    'headsign' => $legs[0]['headsign'] ?? null,
                    'color' => KvbLineColors::for($line),
                    'depart_at' => CarbonImmutable::parse($legs[0]['startTime'])->setTimezone(config('app.timezone'))->toIso8601String(),
                    'arrive_at' => CarbonImmutable::parse($legs[0]['endTime'])->setTimezone(config('app.timezone'))->toIso8601String(),
                    'cancelled' => (bool) ($legs[0]['cancelled'] ?? false),
                ];
            }

            return $rides;
        }, array_keys(array_values($stretches)));
    }

    /**
     * The runs leaving a stop soon, one per line and headsign: the trips a line map is drawn from.
     *
     * @return list<array{line: string, headsign: string, trip: string}>
     */
    public function tripsAt(string $stopId, int $count = 40): array
    {
        $timeout = $this->callTimeout(5.0) ?? throw new \RuntimeException('stoptimes time budget spent');

        $response = PerfLogger::measure("ext:{$this->source()}.stoptimes", fn () => Http::baseUrl($this->baseUrl())
            ->timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/stoptimes', ['stopId' => $stopId, 'n' => $count])
            ->throw());

        $seen = [];
        foreach ($response->json('stopTimes', []) as $time) {
            $line = trim((string) ($time['routeShortName'] ?? ''));
            $headsign = trim((string) ($time['headsign'] ?? ''));
            if ($line === '' || ! isset($time['tripId'])) {
                continue;
            }
            $seen["{$line}|{$headsign}"] ??= ['line' => $line, 'headsign' => $headsign, 'trip' => (string) $time['tripId']];
        }

        return array_values($seen);
    }

    /**
     * One run from its first stop to its last: every stop, and the way it goes along the tracks.
     *
     * @return array{stops: list<array{name: string, lat: float, lng: float, id: string|null}>, points: list<array{0: float, 1: float}>}|null
     */
    public function tripPath(string $tripId): ?array
    {
        $timeout = $this->callTimeout(5.0) ?? throw new \RuntimeException('trip time budget spent');

        $response = PerfLogger::measure("ext:{$this->source()}.trip", fn () => Http::baseUrl($this->baseUrl())
            ->timeout($timeout)
            ->connectTimeout(min(3.0, $timeout))
            ->withHeaders(['User-Agent' => 'expadu.com'])
            ->get('/api/v1/trip', ['tripId' => $tripId])
            ->throw());

        $stops = [];
        $points = [];
        foreach ($response->json('legs', []) as $leg) {
            $places = [$leg['from'] ?? null, ...($leg['intermediateStops'] ?? []), $leg['to'] ?? null];
            foreach ($places as $place) {
                if (isset($place['name'], $place['lat'], $place['lon'])) {
                    $stops[] = ['name' => (string) $place['name'], 'lat' => (float) $place['lat'], 'lng' => (float) $place['lon'], 'id' => $place['stopId'] ?? null];
                }
            }
            $geometry = $leg['legGeometry'] ?? null;
            if (is_array($geometry) && isset($geometry['points'])) {
                array_push($points, ...(Polyline::decode((string) $geometry['points'], (int) ($geometry['precision'] ?? 6)) ?? []));
            }
        }

        return $stops === [] ? null : ['stops' => $stops, 'points' => $points];
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

    /**
     * A walk's turns as the router gives them: which way, onto which street, how far, the line
     * of it (precision 7, as every map), and the floors when it changes level (lifts, stairs).
     *
     * @param  array<int, mixed>  $steps
     * @return list<array{direction: string, street: string, metres: int, polyline: string|null, from_level: float|null, to_level: float|null}>|null
     */
    private function mapSteps(array $steps): ?array
    {
        $mapped = array_values(array_filter(array_map(fn ($step) => is_array($step) ? [
            'direction' => strtolower((string) ($step['relativeDirection'] ?? 'continue')),
            'street' => trim((string) ($step['streetName'] ?? '')),
            'metres' => (int) round((float) ($step['distance'] ?? 0)),
            'polyline' => isset($step['polyline']['points'])
                ? Polyline::atPrecision((string) $step['polyline']['points'], (int) ($step['polyline']['precision'] ?? Polyline::PRECISION))
                : null,
            'from_level' => isset($step['fromLevel']) ? (float) $step['fromLevel'] : null,
            'to_level' => isset($step['toLevel']) ? (float) $step['toLevel'] : null,
        ] : null, $steps)));

        return $mapped === [] ? null : $mapped;
    }

    /**
     * A walk or ride on the street, the right way round. Asked to arrive by a time, the router can
     * hand a direct walk back from its end to its start (line and turns alike); then both are turned
     * round, and each turn's way is worked out again from the line (AppJourney).
     *
     * @param  array<string, mixed>  $leg
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private function forwards(array $leg, array $from, array $to): array
    {
        $precision = (int) ($leg['legGeometry']['precision'] ?? Polyline::PRECISION);
        $points = isset($leg['legGeometry']['points']) ? Polyline::decode((string) $leg['legGeometry']['points'], $precision) : null;
        if ($points === null || count($points) < 2 || ! isset($from['lat'], $from['lon'], $to['lat'], $to['lon'])) {
            return $leg;
        }
        // Metres between a line point and a place, flat (fine across a city).
        $metres = fn (array $point, array $place): float => hypot(
            ($point[1] - (float) $place['lon']) * 111_320 * cos(deg2rad((float) $place['lat'])),
            ($point[0] - (float) $place['lat']) * 111_320,
        );
        // Only a line that plainly runs from the end to the start is turned round.
        $backwards = $metres($points[0], $to) < 150 && $metres($points[count($points) - 1], $from) < 150
            && $metres($points[0], $from) > $metres($points[0], $to);
        if (! $backwards) {
            return $leg;
        }

        $leg['legGeometry']['points'] = Polyline::encode(array_reverse($points), $precision);
        $leg['steps'] = array_reverse(array_map(function ($step) {
            if (! is_array($step)) {
                return $step;
            }
            if (isset($step['polyline']['points'])) {
                $stepPrecision = (int) ($step['polyline']['precision'] ?? Polyline::PRECISION);
                $stepPoints = Polyline::decode((string) $step['polyline']['points'], $stepPrecision);
                $step['polyline']['points'] = $stepPoints === null ? $step['polyline']['points'] : Polyline::encode(array_reverse($stepPoints), $stepPrecision);
            }
            // Left and right flip on the way back: the turns are read again from the line.
            if (! in_array(strtoupper((string) ($step['relativeDirection'] ?? '')), ['STAIRS', 'ELEVATOR'], true)) {
                $step['relativeDirection'] = 'CONTINUE';
            }
            [$step['fromLevel'], $step['toLevel']] = [$step['toLevel'] ?? null, $step['fromLevel'] ?? null];

            return $step;
        }, (array) ($leg['steps'] ?? [])));

        return $leg;
    }
}
