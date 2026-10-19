<?php

namespace App\Day;

use App\Composer\TravelEstimator;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\Journey;
use Carbon\CarbonImmutable;

/**
 * The trip between two consecutive plans, checked only as far as real routes
 * allow. A walk that fits settles it (you can always walk); otherwise a
 * planned journey decides. Anything not worked out — a missing end, a place
 * without coordinates, a router that didn't answer in time — reads
 * "not_checked", never a guessed travel time.
 */
class PlanLinks
{
    /** Every link on the day shares this much routing time. */
    private const BUDGET_SECONDS = 4.0;

    /** Don't start a route call with less time than this left. */
    private const MIN_CALL_SECONDS = 1.0;

    private const SAME_PLACE_METRES = 150.0;

    public function __construct(
        private readonly RouteService $routes,
        private readonly TravelEstimator $distance,
    ) {}

    /**
     * @param  list<DayItem>  $items  the day's items, sorted by start
     * @return list<array<string, mixed>>
     */
    public function between(array $items, CarbonImmutable $now): array
    {
        $timed = array_values(array_filter($items, fn (DayItem $item) => $item->startAt !== null && ! $item->completed()));
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $links = [];

        for ($i = 1; $i < count($timed); $i++) {
            $from = $timed[$i - 1];
            $to = $timed[$i];
            // Only trips still ahead are worth checking.
            if (! $to->startAt->greaterThan($now)) {
                continue;
            }
            $links[] = $this->link($from, $to, $deadline);
        }

        return $links;
    }

    /**
     * @return array<string, mixed>
     */
    private function link(DayItem $from, DayItem $to, float $deadline): array
    {
        $margin = NextTrip::marginFor($to);
        $gap = $from->endAt === null ? null : (int) floor(($to->startAt->getTimestamp() - $from->endAt->getTimestamp()) / 60);
        $link = ['from_id' => $from->id, 'to_id' => $to->id, 'gap_min' => $gap, 'margin_min' => $margin, 'travel_min' => null, 'basis' => null];

        if ($gap === null) {
            return [...$link, 'status' => 'not_checked', 'reason' => 'end_unknown'];
        }
        if ($gap < 0) {
            return [...$link, 'status' => 'conflict', 'reason' => 'overlap'];
        }
        $a = $from->point();
        $b = $to->point();
        if ($a === null || $b === null) {
            return [...$link, 'status' => 'not_checked', 'reason' => 'location_unknown'];
        }
        if (1000 * $this->distance->haversineKm($a->lat, $a->lng, $b->lat, $b->lng) <= self::SAME_PLACE_METRES) {
            return [...$link, 'travel_min' => 0, 'basis' => 'same_place', 'status' => 'ok', 'reason' => 'same_place'];
        }

        if ($this->left($deadline) >= self::MIN_CALL_SECONDS) {
            $walk = $this->routes->withTimeBudget($this->left($deadline))->travelMatrix($a, [$b], 'WALK')[0] ?? null;
            if ($walk !== null && $walk + $margin <= $gap) {
                return [...$link, 'travel_min' => $walk, 'basis' => 'walk', 'status' => 'ok', 'reason' => 'walk_fits'];
            }
        }

        if ($this->left($deadline) < self::MIN_CALL_SECONDS) {
            return [...$link, 'status' => 'not_checked', 'reason' => 'not_checked'];
        }
        $result = $this->routes->withTimeBudget($this->left($deadline))->plan($a, $b, $to->startAt, 6, true);
        if ($result->source === 'degraded') {
            return [...$link, 'status' => 'not_checked', 'reason' => 'routing_unavailable'];
        }
        $usable = array_values(array_filter(
            $result->journeys,
            fn (Journey $j) => $j->departAt->getTimestamp() >= $from->endAt->getTimestamp(),
        ));
        if ($usable === []) {
            return [...$link, 'basis' => 'transit', 'status' => 'conflict', 'reason' => 'no_connection_in_time'];
        }
        usort($usable, fn (Journey $x, Journey $y) => $x->arriveAt <=> $y->arriveAt);
        $best = $usable[0];
        $spare = (int) floor(($to->startAt->getTimestamp() - $best->arriveAt->getTimestamp()) / 60);
        $travel = (int) ceil(($best->arriveAt->getTimestamp() - $best->departAt->getTimestamp()) / 60);

        return [...$link, 'travel_min' => $travel, 'basis' => 'transit',
            'status' => $spare >= $margin ? 'ok' : ($spare >= 0 ? 'tight' : 'conflict'),
            'reason' => $spare >= $margin ? 'transit_fits' : ($spare >= 0 ? 'only_tight' : 'no_connection_in_time')];
    }

    private function left(float $deadline): float
    {
        return max(0.0, $deadline - microtime(true));
    }
}
