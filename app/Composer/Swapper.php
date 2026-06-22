<?php

namespace App\Composer;

use App\Composer\Contracts\EstimatesTravel;

/**
 * "Show me another" — re-scores ONE slot against its frozen neighbors
 * and returns the plan with the next-best alternative in place. The
 * user fixing the last 10% is the design: it forgives scoring
 * imperfection without re-running the whole composition.
 */
class Swapper
{
    public function __construct(
        private readonly PlanScorer $scorer,
        private readonly EstimatesTravel $travel,
    ) {}

    /**
     * @param  list<Candidate>  $feasible
     * @param  list<string>  $rejectedIds  candidates already swapped away in this slot
     */
    public function swap(
        Plan $plan,
        int $slotIndex,
        array $feasible,
        ScoringContext $context,
        float $originLat,
        float $originLng,
        array $rejectedIds = [],
    ): ?Plan {
        if (! isset($plan->slots[$slotIndex])) {
            return null;
        }

        $target = $plan->slots[$slotIndex];
        if ($target->candidate->isFixedTime()) {
            return null; // fixed-time events are anchors, not swappable
        }

        $previous = $plan->slots[$slotIndex - 1] ?? null;
        $next = $plan->slots[$slotIndex + 1] ?? null;

        $cursor = $previous?->endAt ?? $plan->constraints->windowStart;
        $cursorLat = $previous?->candidate->lat ?? $originLat;
        $cursorLng = $previous?->candidate->lng ?? $originLng;
        $gapEnd = $next?->startAt ?? $plan->constraints->windowEnd;

        $usedIds = array_map(fn (PlanSlot $slot) => $slot->candidate->id, $plan->slots);
        $excluded = [...$usedIds, ...$rejectedIds];

        $neighbors = array_values(array_filter(
            $plan->slots,
            fn (PlanSlot $slot, int $i) => $i !== $slotIndex,
            ARRAY_FILTER_USE_BOTH,
        ));

        $best = null;
        $bestScore = -INF;

        foreach ($feasible as $candidate) {
            if ($candidate->isFixedTime() || in_array($candidate->id, $excluded, true)) {
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

            $score = $this->scorer->score($candidate, $neighbors, $cursor, $cursorLat, $cursorLng, $context);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = new PlanSlot($candidate, $start, $end, $travelMin);
            }
        }

        if ($best === null) {
            return null;
        }

        $slots = $plan->slots;
        $slots[$slotIndex] = $best;

        return new Plan($plan->constraints, array_values($slots));
    }
}
