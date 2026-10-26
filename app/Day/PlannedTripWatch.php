<?php

namespace App\Day;

use App\Composer\TravelEstimator;
use App\Models\PlannedTrip;
use App\Services\DisruptionService;
use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;
use App\Transit\TransitNotices;
use Carbon\CarbonImmutable;

/**
 * "We keep watching it" (Plan a trip's saved way): whether the planned way
 * still holds, from live data only. Its ride is found again in a fresh plan
 * (by the feed's trip id, else its line, boarding stop and timetable time);
 * the way changes when that ride is cancelled, when it runs late into the
 * margin before the plan starts, or when KVB publishes a notice for its line
 * after the way was picked. And the weather: rain forecast on a long walk,
 * when another way still in time walks half as much (no better way, no
 * message). A ride that can't be found again, or times without live data,
 * say nothing: showing nothing beats guessing.
 */
class PlannedTripWatch
{
    /** One planned way never holds the run longer than this. */
    private const BUDGET_SECONDS = 6.0;

    private const SAME_STOP_METRES = 150.0;

    private const SAME_TIME_SECONDS = 60;

    /** The weather: a walk this long (in all) is worth a drier way… */
    private const RAIN_WALK_MIN = 8;

    /** …when an hour of it brings this much rain (mm/h) or this likely (%). */
    private const RAIN_MM = 1.0;

    private const RAIN_PROBABILITY = 70;

    /** A departure in the last minute still counts as catchable. */
    private const GRACE_SECONDS = 60;

    public function __construct(
        private readonly RouteService $routes,
        private readonly DayProjection $day,
        private readonly NextTrip $next,
        private readonly DisruptionService $disruptions,
        private readonly TravelEstimator $distance,
        private readonly WeatherService $weather,
    ) {}

    /**
     * The change of plan, or null while the way holds (or can't be told).
     *
     * @return array{kind: string, ride: array{line: ?string, mode: string, time: string}, arrive_time: ?string, late_min: ?int, spare_min: ?int, start_time: string, notice: ?string, walk_min: ?int, instead: ?array{leave_time: string, arrive_time: string, spare_min: int, status: string, ride: ?array{line: ?string, mode: string, time: string}, walk_min?: int}}|null
     */
    public function check(PlannedTrip $trip, CarbonImmutable $now): ?array
    {
        $target = collect($this->day->itemsOn($trip->user, $trip->date->toDateString()))
            ->first(fn (DayItem $item) => $item->id === $trip->target_id);
        if ($target === null || $target->startAt === null || ! $target->startAt->greaterThan($now)) {
            return null;
        }

        $planned = $trip->toJourney();
        $ride = collect($planned->legs)->first(fn (Leg $leg) => $leg->isTransit());
        if ($ride === null) {
            return null;
        }
        $from = $planned->legs[0]->from->point;
        $to = $target->point() ?? $planned->legs[array_key_last($planned->legs)]->to->point;

        $result = $this->routes->withTimeBudget(self::BUDGET_SECONDS)->plan($from, $to, $planned->departAt->subMinutes(2), 6);
        if ($result->source === 'degraded') {
            return null;
        }
        $live = $this->findRide($result->journeys, $ride);

        $kind = null;
        $lateMin = null;
        $spare = null;
        $notice = null;
        if ($live !== null) {
            [$journey, $leg] = $live;
            if (collect($journey->legs)->contains(fn (Leg $l) => $l->cancelled)) {
                $kind = 'cancelled';
            } elseif ($leg->realtime) {
                $spare = $this->minutes($journey->arriveAt, $target->startAt);
                $late = $this->minutes($planned->arriveAt, $journey->arriveAt);
                if ($late > 0 && $spare < NextTrip::marginFor($target)) {
                    $kind = 'late';
                    $lateMin = $late;
                }
            }
        }
        if ($kind === null) {
            $notice = $this->noticeFor($planned, $trip->planned_at ?? $trip->created_at?->toImmutable());
            $kind = $notice === null ? null : 'notice';
        }
        $drier = null;
        if ($kind === null) {
            $drier = $this->drierWay($planned, $target, $from, $to, $now);
            $kind = $drier === null ? null : 'weather';
        }
        if ($kind === null) {
            return null;
        }

        return [
            'kind' => $kind,
            'ride' => $this->rideOf($ride),
            'arrive_time' => $kind === 'late' ? $live[0]->arriveAt->setTimezone('Europe/Berlin')->format('H:i') : null,
            'late_min' => $lateMin,
            'spare_min' => $spare,
            'start_time' => $target->startAt->setTimezone('Europe/Berlin')->format('H:i'),
            'notice' => $notice,
            'walk_min' => $kind === 'weather' ? $this->walking($planned) : null,
            'instead' => match ($kind) {
                'notice' => null,
                'weather' => $drier,
                default => $this->instead($target, $from, $ride, $now),
            },
        ];
    }

    /**
     * Rain on the planned way's walk (8 min or more in all): an hour of the
     * city's forecast while walking brings 1 mm/h or a 70% chance. Then the
     * way still in time that walks least, if it walks half as much or less.
     *
     * @return array{leave_time: string, arrive_time: string, spare_min: int, status: string, ride: ?array{line: ?string, mode: string, time: string}, walk_min: int}|null
     */
    private function drierWay(Journey $planned, DayItem $target, GeoPoint $from, GeoPoint $to, CarbonImmutable $now): ?array
    {
        $walk = $this->walking($planned);
        if ($walk < self::RAIN_WALK_MIN || ! $this->rainOnTheWalk($planned)) {
            return null;
        }

        $result = $this->routes->withTimeBudget(self::BUDGET_SECONDS)->plan($from, $to, $target->startAt, 8, true);
        if ($result->source === 'degraded') {
            return null;
        }
        $margin = NextTrip::marginFor($target);
        $ways = array_values(array_filter(
            $result->journeys,
            fn (Journey $j) => $j->departAt->getTimestamp() >= $now->getTimestamp() - self::GRACE_SECONDS
                && ! collect($j->legs)->contains(fn (Leg $l) => $l->cancelled)
                && $this->minutes($j->arriveAt, $target->startAt) >= $margin
                && $this->walking($j) <= intdiv($walk, 2),
        ));
        if ($ways === []) {
            return null;
        }
        // The least walking; the latest leave between equals.
        usort($ways, fn (Journey $a, Journey $b) => [$this->walking($a), $b->departAt] <=> [$this->walking($b), $a->departAt]);
        $way = $ways[0];
        $first = collect($way->legs)->first(fn (Leg $leg) => $leg->isTransit());
        $tz = 'Europe/Berlin';

        return [
            'leave_time' => $way->departAt->setTimezone($tz)->format('H:i'),
            'arrive_time' => $way->arriveAt->setTimezone($tz)->format('H:i'),
            'spare_min' => $this->minutes($way->arriveAt, $target->startAt),
            'status' => 'fits',
            'ride' => $first === null ? null : $this->rideOf($first),
            'walk_min' => $this->walking($way),
        ];
    }

    private function rainOnTheWalk(Journey $journey): bool
    {
        $tz = 'Europe/Berlin';
        $days = [];
        foreach ($journey->legs as $leg) {
            if ($leg->mode !== 'walk') {
                continue;
            }
            $start = $leg->departAt->setTimezone($tz);
            $end = $leg->arriveAt->setTimezone($tz);
            foreach (array_unique([$start->toDateString(), $end->toDateString()]) as $date) {
                $days[$date] ??= $this->weather->getDayForecast($date)['hours'] ?? [];
                foreach ($days[$date] as $hour) {
                    if (! is_string($hour['time'] ?? null)) {
                        continue;
                    }
                    $at = CarbonImmutable::parse($hour['time'])->setTimezone($tz);
                    $overlaps = $at->lessThan($end) && $at->addHour()->greaterThan($start);
                    if ($overlaps && ((float) ($hour['precip'] ?? 0) >= self::RAIN_MM
                        || (int) ($hour['precip_probability'] ?? 0) >= self::RAIN_PROBABILITY)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Minutes on foot, in all. */
    private function walking(Journey $journey): int
    {
        return (int) collect($journey->legs)->filter(fn (Leg $leg) => $leg->mode === 'walk')->sum(fn (Leg $leg) => $leg->durationMin);
    }

    /**
     * The planned ride in a fresh answer: the same trip in the feed, else the
     * same line from the same stop at the same timetable time.
     *
     * @param  list<Journey>  $journeys
     * @return array{0: Journey, 1: Leg}|null
     */
    private function findRide(array $journeys, Leg $ride): ?array
    {
        foreach ($journeys as $journey) {
            foreach ($journey->legs as $leg) {
                if ($leg->isTransit() && $this->sameRide($leg, $ride)) {
                    return [$journey, $leg];
                }
            }
        }

        return null;
    }

    private function sameRide(Leg $leg, Leg $ride): bool
    {
        if ($leg->tripId !== null && $ride->tripId !== null) {
            return $leg->tripId === $ride->tripId;
        }
        $timetable = fn (Leg $l) => ($l->scheduledDepartAt ?? $l->departAt)->getTimestamp();

        return $leg->lineName === $ride->lineName
            && abs($timetable($leg) - $timetable($ride)) <= self::SAME_TIME_SECONDS
            && 1000 * $this->distance->haversineKm($leg->from->point->lat, $leg->from->point->lng, $ride->from->point->lat, $ride->from->point->lng) <= self::SAME_STOP_METRES;
    }

    /** A KVB notice for one of the way's lines, published after it was picked. */
    private function noticeFor(Journey $planned, ?CarbonImmutable $since): ?string
    {
        $lines = $planned->lines();
        foreach ($this->disruptions->getLineDisruptions() as $notice) {
            // VRS's and DELFI's notices show on boards; they aren't pushed yet.
            if (in_array($notice['source'] ?? null, TransitNotices::SOURCES, true)) {
                continue;
            }
            $published = CarbonImmutable::parse($notice['started_at']);
            if ($since !== null && ! $published->greaterThan($since)) {
                continue;
            }
            if (array_intersect(array_map('strval', $notice['affected_lines'] ?? []), $lines) !== []) {
                // KVB's notice in plain English when one is written (its headline,
                // then what it means), else its own title.
                $brief = $notice['brief'] ?? null;

                return $brief === null
                    ? (string) $notice['title']
                    : rtrim((string) $brief['headline'], '.').(($brief['text'] ?? null) ? '. '.$brief['text'] : '');
            }
        }

        return null;
    }

    /**
     * The way Leave now would take now from the same start, when it isn't
     * the planned ride itself.
     *
     * @return array{leave_time: string, arrive_time: string, spare_min: int, status: string, ride: ?array{line: ?string, mode: string, time: string}}|null
     */
    private function instead(DayItem $target, GeoPoint $from, Leg $ride, CarbonImmutable $now): ?array
    {
        $answer = $this->next->for($target, new TripOrigin($from, '', 'plan', $now), $now);
        $journey = is_array($answer['journey'] ?? null) ? Journey::fromArray($answer['journey']) : null;
        if ($journey === null || ! in_array($answer['status'], ['fits', 'tight', 'late'], true)) {
            return null;
        }
        $first = collect($journey->legs)->first(fn (Leg $leg) => $leg->isTransit());
        if ($first !== null && $this->sameRide($first, $ride)) {
            return null;
        }

        return [
            'leave_time' => $answer['leave_time'],
            'arrive_time' => $answer['arrive_time'],
            'spare_min' => $answer['spare_min'],
            'status' => $answer['status'],
            'ride' => $first === null ? null : $this->rideOf($first),
        ];
    }

    /**
     * @return array{line: ?string, mode: string, time: string}
     */
    private function rideOf(Leg $leg): array
    {
        return [
            'line' => $leg->lineName,
            'mode' => $leg->mode,
            'time' => ($leg->scheduledDepartAt ?? $leg->departAt)->setTimezone('Europe/Berlin')->format('H:i'),
        ];
    }

    private function minutes(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / 60);
    }
}
