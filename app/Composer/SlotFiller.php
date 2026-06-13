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

        // 1. Anchor fixed-time candidates. Appointments are sacred, so they
        //    claim their slot before curated events; then earliest first.
        $fixed = array_filter($feasible, fn (Candidate $c) => $c->isFixedTime());
        usort($fixed, function (Candidate $a, Candidate $b) {
            if ($a->isAppointment() !== $b->isAppointment()) {
                return $a->isAppointment() ? -1 : 1;
            }

            return $a->fixedStart <=> $b->fixedStart;
        });

        foreach ($fixed as $anchor) {
            $start = $anchor->fixedStart;
            $end = $start->addMinutes($anchor->typicalDurationMin);
            if ($this->overlapsAny($slots, $start, $end)) {
                continue;
            }
            $slots[] = new PlanSlot($anchor, $start, $end, 0);
            $used[$anchor->id] = true;
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

        return new Plan($constraints, $this->bufferAnchors(array_values($slots)));
    }

    /**
     * Anchors are placed before the greedy fill, so their travel-from-
     * previous is unknown at placement time. Once everything is laid out,
     * annotate each anchor with the trip in from the slot before it — this
     * is what powers the "leave {place} by 13:38" line on appointments.
     *
     * @param  list<PlanSlot>  $slots
     * @return list<PlanSlot>
     */
    private function bufferAnchors(array $slots): array
    {
        foreach ($slots as $i => $slot) {
            if ($i === 0 || ! $slot->candidate->isFixedTime() || $slot->travelMinFromPrevious > 0) {
                continue;
            }

            $previous = $slots[$i - 1];
            $travelMin = $this->travel->minutesBetween(
                $previous->candidate->lat,
                $previous->candidate->lng,
                $slot->candidate->lat,
                $slot->candidate->lng,
            );

            if ($travelMin > 0) {
                $slots[$i] = new PlanSlot($slot->candidate, $slot->startAt, $slot->endAt, $travelMin);
            }
        }

        return $slots;
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
