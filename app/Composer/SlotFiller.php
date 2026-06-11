<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * Greedy plan composition: anchor fixed-time events first, then fill
 * the remaining gaps left→right, re-scoring the whole candidate set
 * against the updated plan state (current position, used categories,
 * remaining time) after each placement. Deterministic for fixed input.
 */
class SlotFiller
{
    private const MAX_SLOTS_PER_DAY = 6;

    public function __construct(
        private readonly PlanScorer $scorer,
        private readonly TravelEstimator $travel,
    ) {}

    /**
     * @param  list<Candidate>  $feasible
     */
    public function fill(
        Constraints $constraints,
        array $feasible,
        ScoringContext $context,
        float $originLat,
        float $originLng,
    ): Plan {
        $slots = [];
        $used = [];

        // 1. Anchor fixed-time events (earliest first; one per overlap window)
        $events = array_filter($feasible, fn (Candidate $c) => $c->isFixedTime());
        usort($events, fn (Candidate $a, Candidate $b) => $a->fixedStart <=> $b->fixedStart);

        foreach ($events as $event) {
            $start = $event->fixedStart;
            $end = $start->addMinutes($event->typicalDurationMin);
            if ($this->overlapsAny($slots, $start, $end)) {
                continue;
            }
            $slots[] = new PlanSlot($event, $start, $end, 0);
            $used[$event->id] = true;
            if (count($slots) >= self::MAX_SLOTS_PER_DAY) {
                break;
            }
        }

        usort($slots, fn (PlanSlot $a, PlanSlot $b) => $a->startAt <=> $b->startAt);

        // 2. Greedy fill left→right
        $cursor = $constraints->windowStart;
        $cursorLat = $originLat;
        $cursorLng = $originLng;

        while (count($slots) < self::MAX_SLOTS_PER_DAY) {
            // Jump the cursor past any anchored slot we've reached
            foreach ($slots as $slot) {
                if ($slot->startAt->lessThanOrEqualTo($cursor) && $slot->endAt->greaterThan($cursor)) {
                    $cursor = $slot->endAt;
                    $cursorLat = $slot->candidate->lat;
                    $cursorLng = $slot->candidate->lng;
                }
            }

            $gapEnd = $this->nextAnchorStart($slots, $cursor) ?? $constraints->windowEnd;
            $best = $this->bestFor($feasible, $used, $slots, $cursor, $cursorLat, $cursorLng, $gapEnd, $context);

            if ($best === null) {
                // Nothing fits this gap — jump to after the next anchor, or stop.
                $nextAnchor = $this->nextAnchorStart($slots, $cursor);
                if ($nextAnchor === null) {
                    break;
                }
                $cursor = $this->slotEndingAfter($slots, $nextAnchor);

                continue;
            }

            [$candidate, $travelMin] = $best;
            $start = $cursor->addMinutes($travelMin);
            if ($candidate->opensAt !== null && $candidate->opensAt->greaterThan($start)) {
                $start = $candidate->opensAt;
            }
            $end = $start->addMinutes($candidate->typicalDurationMin);

            $slots[] = new PlanSlot($candidate, $start, $end, $travelMin);
            $used[$candidate->id] = true;
            usort($slots, fn (PlanSlot $a, PlanSlot $b) => $a->startAt <=> $b->startAt);

            $cursor = $end;
            $cursorLat = $candidate->lat;
            $cursorLng = $candidate->lng;
        }

        return new Plan($constraints, array_values($slots));
    }

    /**
     * Best non-fixed candidate that fits between cursor and gapEnd.
     *
     * @param  list<Candidate>  $feasible
     * @param  array<string, bool>  $used
     * @param  list<PlanSlot>  $slots
     * @return array{0: Candidate, 1: int}|null
     */
    private function bestFor(
        array $feasible,
        array $used,
        array $slots,
        CarbonImmutable $cursor,
        float $cursorLat,
        float $cursorLng,
        CarbonImmutable $gapEnd,
        ScoringContext $context,
    ): ?array {
        $best = null;
        $bestScore = -INF;

        foreach ($feasible as $candidate) {
            if ($candidate->isFixedTime() || isset($used[$candidate->id])) {
                continue;
            }

            $travelMin = $this->travel->minutesBetween($cursorLat, $cursorLng, $candidate->lat, $candidate->lng);
            $start = $cursor->addMinutes($travelMin);
            if ($candidate->opensAt !== null && $candidate->opensAt->greaterThan($start)) {
                $start = $candidate->opensAt;
            }
            $end = $start->addMinutes($candidate->typicalDurationMin);

            if ($end->greaterThan($gapEnd)) {
                continue;
            }
            if ($candidate->closesAt !== null && $end->greaterThan($candidate->closesAt)) {
                continue;
            }

            $score = $this->scorer->score($candidate, $slots, $cursor, $cursorLat, $cursorLng, $context);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [$candidate, $travelMin];
            }
        }

        return $best;
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function overlapsAny(array $slots, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        foreach ($slots as $slot) {
            if ($start->lessThan($slot->endAt) && $end->greaterThan($slot->startAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function nextAnchorStart(array $slots, CarbonImmutable $after): ?CarbonImmutable
    {
        $next = null;
        foreach ($slots as $slot) {
            if ($slot->startAt->greaterThan($after) && ($next === null || $slot->startAt->lessThan($next))) {
                $next = $slot->startAt;
            }
        }

        return $next;
    }

    /**
     * @param  list<PlanSlot>  $slots
     */
    private function slotEndingAfter(array $slots, CarbonImmutable $startAt): CarbonImmutable
    {
        foreach ($slots as $slot) {
            if ($slot->startAt->equalTo($startAt)) {
                return $slot->endAt;
            }
        }

        return $startAt;
    }
}
