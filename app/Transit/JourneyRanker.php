<?php

namespace App\Transit;

use App\Transit\Dto\Journey;
use App\Transit\Dto\Leg;

/**
 * The order ways are offered in: the easiest and closest first, then real
 * alternatives. A way is scored by when you're there, plus what walking and
 * changing cost you; the next departure of a way already listed waits behind
 * every different way (the owner, 3 Nov 2026: "my easier and closer, including
 * alternatives"). Nothing is dropped.
 */
final class JourneyRanker
{
    private const WALK_COST = 0.8;

    /** On top of WALK_COST, per minute past LONG_WALK_AFTER_MIN. */
    private const LONG_WALK_COST = 0.7;

    private const LONG_WALK_AFTER_MIN = 12;

    private const CHANGE_COST = 5;

    /** Walking there leads when it's this short and about as quick as riding. */
    private const PLAIN_WALK_MAX_MIN = 15;

    private const PLAIN_WALK_SLACK_MIN = 2;

    /** Walking further than this is a last resort, unless riding takes even longer. */
    private const FAR_WALK_MIN = 30;

    /** A way there this long after the first is no alternative to the first's next run. */
    private const ALTERNATIVE_WITHIN_MIN = 45;

    /**
     * @param  list<Journey>  $journeys
     * @param  bool  $arriveBy  Every way then ends about the same time, so how long it takes counts instead.
     * @return list<Journey>
     */
    public function rank(array $journeys, bool $arriveBy = false): array
    {
        $by = fn (Journey $a, Journey $b): int => [$this->score($a, $arriveBy), $b->departAt] <=> [$this->score($b, $arriveBy), $a->departAt];

        $cancelled = array_values(array_filter($journeys, $this->isCancelled(...)));
        $running = array_values(array_filter($journeys, fn (Journey $j): bool => ! $this->isCancelled($j)));
        usort($running, $by);

        $rides = array_values(array_filter($running, fn (Journey $j): bool => $j->mode() === 'transit'));
        $walk = collect($running)->first(fn (Journey $j): bool => $j->mode() === 'walk');
        $bikes = array_values(array_filter($running, fn (Journey $j): bool => $j->mode() === 'bike'));

        $different = [];
        $again = [];
        foreach ($rides as $ride) {
            $way = $this->way($ride);
            if (isset($different[$way])) {
                $again[] = $ride;
            } else {
                $different[$way] = $ride;
            }
        }
        $different = array_values($different);

        if (! $arriveBy && $different !== []) {
            $latest = $different[0]->arriveAt->addMinutes(self::ALTERNATIVE_WITHIN_MIN);
            $again = [...$again, ...array_filter($different, fn (Journey $j): bool => $j->arriveAt > $latest)];
            $different = array_values(array_filter($different, fn (Journey $j): bool => $j->arriveAt <= $latest));
            usort($again, $by);
        }

        if ($walk === null) {
            return [...$different, ...$again, ...$bikes, ...$cancelled];
        }

        if ($this->walkLeads($walk, $rides, $arriveBy)) {
            return [$walk, ...$different, ...$again, ...$bikes, ...$cancelled];
        }

        if ($this->walkIsFar($walk, $rides)) {
            return [...$different, ...$again, $walk, ...$bikes, ...$cancelled];
        }

        $ways = [...$different, $walk];
        usort($ways, $by);

        return [...$ways, ...$again, ...$bikes, ...$cancelled];
    }

    private function score(Journey $journey, bool $arriveBy): float
    {
        $walking = $this->walkingMin($journey);

        return ($arriveBy ? $journey->durationMin : $journey->arriveAt->getTimestamp() / 60)
            + self::WALK_COST * $walking
            + self::LONG_WALK_COST * max(0, $walking - self::LONG_WALK_AFTER_MIN)
            + self::CHANGE_COST * $journey->transfers;
    }

    /**
     * Minutes on foot. A 0 m walk at either end is the router's wait at the stop, not walking; between
     * two rides it is the change from one platform to the other, which our MOTIS also gives as 0 m
     * (six minutes from the S-Bahn to the tram at Hansaring). Counted as none, it put a way with two
     * changes above the tram straight there.
     */
    /**
     * A way as a rider tells ways apart: where each ride gets on and off. The 3, 4 and 5 from
     * Appellhofplatz to Friesenplatz for the 12 are one way, and its 4 at 20:06 waits behind the 18
     * to Ebertplatz instead of coming third. A ride without its stops goes by its line.
     */
    private function way(Journey $journey): string
    {
        return implode('>', array_map(
            fn (Leg $leg): string => $leg->from->name !== $leg->to->name ? "{$leg->from->name}~{$leg->to->name}" : (string) $leg->lineName,
            array_values(array_filter($journey->legs, fn (Leg $leg): bool => $leg->isTransit())),
        ));
    }

    private function walkingMin(Journey $journey): int
    {
        $legs = array_values($journey->legs);
        $minutes = 0;
        foreach ($legs as $i => $leg) {
            if ($leg->mode !== 'walk') {
                continue;
            }
            $between = $this->ridesBefore($legs, $i) && $this->ridesAfter($legs, $i);
            if ($leg->distanceM !== 0 || $between) {
                $minutes += $leg->durationMin;
            }
        }

        return $minutes;
    }

    /** @param  list<Leg>  $legs */
    private function ridesBefore(array $legs, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($legs[$i]->isTransit()) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<Leg>  $legs */
    private function ridesAfter(array $legs, int $index): bool
    {
        for ($i = $index + 1; $i < count($legs); $i++) {
            if ($legs[$i]->isTransit()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Journey>  $rides
     */
    private function walkLeads(Journey $walk, array $rides, bool $arriveBy): bool
    {
        if ($rides === []) {
            return true;
        }
        if ($walk->durationMin > self::PLAIN_WALK_MAX_MIN) {
            return false;
        }

        return $arriveBy
            ? $walk->durationMin <= min(array_map(fn (Journey $j): int => $j->durationMin, $rides)) + self::PLAIN_WALK_SLACK_MIN
            : $walk->arriveAt->getTimestamp() <= min(array_map(fn (Journey $j): int => $j->arriveAt->getTimestamp(), $rides)) + self::PLAIN_WALK_SLACK_MIN * 60;
    }

    /**
     * @param  list<Journey>  $rides
     */
    private function walkIsFar(Journey $walk, array $rides): bool
    {
        return $walk->durationMin > self::FAR_WALK_MIN
            && $walk->durationMin >= min(array_map(fn (Journey $j): int => $j->durationMin, $rides));
    }

    private function isCancelled(Journey $journey): bool
    {
        return collect($journey->legs)->contains(fn (Leg $leg): bool => $leg->cancelled);
    }
}
