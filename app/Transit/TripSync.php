<?php

namespace App\Transit;

use App\Models\ActiveTrip;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Leg;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Which run a rider is really on, and the trip from it (the owner, 13 Nov 2026: "the sync when the
 * user has chosen a different tram"). The phone says when it left a ride's stop; the runs serving that
 * stretch (the 16 and the 18 from Ebertplatz to Appellhofplatz, earlier and later ones) say which run
 * that was: the planned one running late, one for sure, or a choice of two or three. Switching plans
 * the rest of the way again from that very run and keeps the old trip a while for Undo; a change a
 * delay broke ("Miss it? The 18 at 17:42") switches the same way, from the change stop.
 */
final class TripSync
{
    /** Runs leaving this close to when the phone left are what it could be on. */
    private const WINDOW_MINUTES = 4;

    /** The closest run within this is sure, when the next is at least SEPARATION_MINUTES further. */
    private const SURE_MINUTES = 1.5;

    private const SEPARATION_MINUTES = 2.0;

    /** How long Undo can put the old trip back. */
    private const UNDO_MINUTES = 30;

    public function __construct(
        private readonly MotisAdapter $motis,
        private readonly TransitousAdapter $transitous,
    ) {}

    /**
     * Which run the phone left a ride's stop on.
     *
     * @return array{planned: bool, sure: array<string, mixed>|null, runs: list<array<string, mixed>>}
     */
    public function check(ActiveTrip $trip, int $legIndex, CarbonImmutable $leftAt): array
    {
        $leg = $this->ride($trip, $legIndex);
        $engine = $this->engine();
        if ($leg === null || $engine === null) {
            return ['planned' => false, 'sure' => null, 'runs' => []];
        }

        $from = new GeoPoint((float) $leg['from']['lat'], (float) $leg['from']['lng']);
        $to = new GeoPoint((float) $leg['to']['lat'], (float) $leg['to']['lng']);
        $runs = $engine->withTimeBudget(4.0)->directRides([['from' => $from, 'to' => $to, 'at' => $leftAt->subMinutes(self::WINDOW_MINUTES + 2)]])[0] ?? [];

        $off = fn (array $run) => abs(CarbonImmutable::parse($run['depart_at'])->getTimestamp() - $leftAt->getTimestamp()) / 60;
        $near = array_values(array_filter($runs, fn (array $run) => ! $run['cancelled'] && $off($run) <= self::WINDOW_MINUTES));
        usort($near, fn (array $a, array $b) => $off($a) <=> $off($b));
        if ($near === []) {
            return ['planned' => false, 'sure' => null, 'runs' => []];
        }

        $best = $near[0];
        $sure = $off($best) <= self::SURE_MINUTES && (! isset($near[1]) || $off($near[1]) - $off($best) >= self::SEPARATION_MINUTES);
        // The run they were meant to be on, late or early: nothing to change.
        if ($sure && $this->isPlanned($leg, $best)) {
            return ['planned' => true, 'sure' => null, 'runs' => []];
        }

        return [
            'planned' => false,
            'sure' => $sure ? $this->run($best) : null,
            'runs' => array_map($this->run(...), array_slice($near, 0, 3)),
        ];
    }

    /**
     * The trip on another run from a ride's stop: the legs before it as they were, the rest planned again
     * from that run. Null when the router has no way on from it.
     *
     * @return array{undo: string, line: string, depart_time: string, arrive_time: string}|null
     */
    public function switchTo(ActiveTrip $trip, int $legIndex, string $line, CarbonImmutable $departAt, ?string $tripId): ?array
    {
        $leg = $this->ride($trip, $legIndex);
        $engine = $this->engine();
        $end = $trip->destination;
        if ($leg === null || $engine === null || ! isset($end['lat'], $end['lng'])) {
            return null;
        }

        try {
            $way = $engine->withTimeBudget(8.0)->wayOnRun(
                new GeoPoint((float) $leg['from']['lat'], (float) $leg['from']['lng']),
                $leg['from']['stop_id'] ?? null,
                new GeoPoint((float) $end['lat'], (float) $end['lng']),
                $departAt, $line, $tripId,
            );
        } catch (\Throwable) {
            return null;
        }
        if ($way === null) {
            return null;
        }

        // Its own steps up to the stop (none, or a minute or two from the stop's place to the stop) add
        // nothing: they are at the stop, or on the tram already.
        $on = $way->legs;
        while ($on !== [] && $on[0]->mode === 'walk' && $on[0]->durationMin <= 2) {
            array_shift($on);
        }
        if ($on === []) {
            return null;
        }
        $old = $trip->journey;
        // What came before: the walk to the stop happened that much earlier or later too, when this is the
        // first ride. After a ride (the next tram at a change a late one broke), what came before is the
        // ride they're on: it stays as it is (moved, it read as a tram leaving later than the one they took).
        $before = array_slice($old['legs'], 0, $legIndex);
        $ridden = collect($before)->contains(fn (array $l) => ! in_array($l['mode'], ['walk', 'bike'], true));
        if (! $ridden) {
            $shift = $on[0]->departAt->getTimestamp() - CarbonImmutable::parse($old['legs'][$legIndex]['depart_at'])->getTimestamp();
            $before = array_map(fn (array $l) => $this->shifted($l, $shift), $before);
        }
        $legs = [...$before, ...array_map(fn (Leg $l) => $l->toArray(), $on)];
        $rides = array_values(array_filter($legs, fn (array $l) => ! in_array($l['mode'], ['walk', 'bike'], true)));
        $departs = CarbonImmutable::parse($legs[0]['depart_at']);
        $journey = [
            ...$old,
            'depart_at' => $departs->toIso8601String(),
            'depart_time' => $departs->format('H:i'),
            'arrive_at' => $way->arriveAt->toIso8601String(),
            'arrive_time' => $way->arriveAt->format('H:i'),
            'duration_min' => (int) round(($way->arriveAt->getTimestamp() - $departs->getTimestamp()) / 60),
            'transfers' => max(0, count($rides) - 1),
            'lines' => array_values(array_filter(array_map(fn (array $l) => $l['line'], $rides))),
            'legs' => $legs,
        ];
        // The other lines and the change notes, as the route list has them.
        $journey = app(RideChoices::class)->add([$journey], 2.0)[0] ?? $journey;

        $undo = (string) Str::uuid();
        Cache::put($this->undoKey($trip, $undo), $old, now()->addMinutes(self::UNDO_MINUTES));
        $trip->update(['journey' => $journey]);

        return [
            'undo' => $undo,
            'line' => $line,
            'depart_time' => $on[0]->departAt->format('H:i'),
            'arrive_time' => $way->arriveAt->format('H:i'),
        ];
    }

    /**
     * A leg moved by that many seconds: the steps before a run they caught earlier or later.
     *
     * @param  array<string, mixed>  $leg
     * @return array<string, mixed>
     */
    private function shifted(array $leg, int $seconds): array
    {
        if ($seconds === 0) {
            return $leg;
        }
        foreach (['depart_at' => 'depart_time', 'arrive_at' => 'arrive_time'] as $at => $time) {
            if (isset($leg[$at])) {
                $moved = CarbonImmutable::parse($leg[$at])->addSeconds($seconds);
                $leg[$at] = $moved->toIso8601String();
                $leg[$time] = $moved->format('H:i');
            }
        }

        return $leg;
    }

    /**
     * A change a delay broke: the first run of that ride's stretch (any line) leaving once they can be
     * at its stop, and the trip switched to it. Null when none leaves within the hour.
     *
     * @return array{undo: string, line: string, depart_time: string, arrive_time: string}|null
     */
    public function catchNext(ActiveTrip $trip, int $legIndex, CarbonImmutable $readyAt): ?array
    {
        $leg = $this->ride($trip, $legIndex);
        $engine = $this->engine();
        if ($leg === null || $engine === null) {
            return null;
        }
        $from = new GeoPoint((float) $leg['from']['lat'], (float) $leg['from']['lng']);
        $to = new GeoPoint((float) $leg['to']['lat'], (float) $leg['to']['lng']);
        $runs = $engine->withTimeBudget(4.0)->directRides([['from' => $from, 'to' => $to, 'at' => $readyAt->subMinutes(1)]])[0] ?? [];
        $next = collect($runs)
            ->filter(fn (array $run) => ! $run['cancelled'] && CarbonImmutable::parse($run['depart_at'])->gte($readyAt))
            ->sortBy(fn (array $run) => CarbonImmutable::parse($run['arrive_at'])->getTimestamp())
            ->first();

        return $next === null ? null
            : $this->switchTo($trip, $legIndex, $next['line'], CarbonImmutable::parse($next['depart_at']), $next['trip']);
    }

    /** Puts back the trip a switch replaced; false when Undo has run out or isn't theirs. */
    public function undo(ActiveTrip $trip, string $undo): bool
    {
        $old = Cache::pull($this->undoKey($trip, $undo));
        if (! is_array($old)) {
            return false;
        }
        $trip->update(['journey' => $old]);

        return true;
    }

    /**
     * Which leg of the trip is the ride the phone means: the one leaving that stop at that time (names
     * come to the phone without the city: "Ebertplatz"), else the index it sent when that is a ride.
     */
    public function rideIndex(ActiveTrip $trip, int $hint, ?string $from, ?CarbonImmutable $at): ?int
    {
        $name = fn (?string $stop) => mb_strtolower(trim((string) preg_replace('/^(Köln|Koeln)\s+/u', '', (string) $stop)));
        if ($from !== null && $at !== null) {
            foreach ($trip->journey['legs'] ?? [] as $i => $leg) {
                if ($this->ride($trip, $i) === null || $name($leg['from']['name'] ?? null) !== $name($from)) {
                    continue;
                }
                foreach (array_filter([$leg['depart_at'] ?? null, $leg['scheduled_depart_at'] ?? null]) as $planned) {
                    if (abs(CarbonImmutable::parse($planned)->getTimestamp() - $at->getTimestamp()) <= 120) {
                        return $i;
                    }
                }
            }
        }

        return $this->ride($trip, $hint) !== null ? $hint : null;
    }

    /**
     * The trip's leg at that index when it is a ride with its stops placed.
     *
     * @return array<string, mixed>|null
     */
    private function ride(ActiveTrip $trip, int $legIndex): ?array
    {
        $leg = $trip->journey['legs'][$legIndex] ?? null;

        return is_array($leg) && ! in_array($leg['mode'] ?? 'walk', ['walk', 'bike'], true)
            && isset($leg['from']['lat'], $leg['from']['lng'], $leg['to']['lat'], $leg['to']['lng']) ? $leg : null;
    }

    /**
     * @param  array<string, mixed>  $leg
     * @param  array<string, mixed>  $run
     */
    private function isPlanned(array $leg, array $run): bool
    {
        if (($leg['trip_id'] ?? null) !== null && $leg['trip_id'] === $run['trip']) {
            return true;
        }
        // Ids differ between routers (a trip planned on failover): the same line leaving within a minute
        // and a half of when it was meant to, by the timetable or live. The next run is minutes away.
        $leaves = CarbonImmutable::parse($run['depart_at'])->getTimestamp();
        foreach (array_filter([$leg['depart_at'] ?? null, $leg['scheduled_depart_at'] ?? null]) as $at) {
            if (($leg['line'] ?? null) === $run['line'] && abs($leaves - CarbonImmutable::parse($at)->getTimestamp()) <= 90) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function run(array $run): array
    {
        $at = CarbonImmutable::parse($run['depart_at']);

        return [
            'line' => $run['line'],
            'headsign' => $run['headsign'],
            'color' => $run['color'],
            'depart_at' => $run['depart_at'],
            'depart_time' => $at->format('H:i'),
            'arrive_time' => CarbonImmutable::parse($run['arrive_at'])->format('H:i'),
            'trip' => $run['trip'],
        ];
    }

    private function undoKey(ActiveTrip $trip, string $undo): string
    {
        return "trip_sync_undo:{$trip->user_id}:{$undo}";
    }

    private function engine(): ?TransitousAdapter
    {
        return match (true) {
            filled(config('services.motis.url')) => $this->motis,
            filled(config('services.transitous.url')) => $this->transitous,
            default => null,
        };
    }
}
