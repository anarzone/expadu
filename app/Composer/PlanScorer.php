<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * One weighted function, not per-case rules — and it scores candidates
 * AGAINST THE CURRENT PLAN STATE, never in isolation (the core lesson
 * from the failed v1 recommender). Tune weights, don't write cases.
 */
class PlanScorer
{
    private const WEIGHTS = [
        'proximity' => 30.0,       // close to the plan's current position
        'variety' => 20.0,         // penalise category repetition
        'weather' => 20.0,         // outdoor in rain is a bad idea
        'closes_soon' => 15.0,     // boost things that won't be possible later
        'area_match' => 10.0,      // inside the preferred Veedels
        'intent' => 15.0,          // learned taste, simple weighted counts
    ];

    public function __construct(
        private readonly TravelEstimator $travel,
    ) {}

    /**
     * @param  list<PlanSlot>  $placedSlots
     */
    public function score(
        Candidate $candidate,
        array $placedSlots,
        CarbonImmutable $cursor,
        float $cursorLat,
        float $cursorLng,
        ScoringContext $context,
    ): float {
        $score = 0.0;

        // Proximity to the plan's current position (decays with travel time)
        $travelMin = $this->travel->minutesBetween($cursorLat, $cursorLng, $candidate->lat, $candidate->lng);
        $score += self::WEIGHTS['proximity'] * max(0.0, 1.0 - $travelMin / 45.0);

        // Variety: each prior slot of the same category costs half the weight
        $sameCategory = count(array_filter(
            $placedSlots,
            fn (PlanSlot $slot) => $slot->candidate->category === $candidate->category,
        ));
        $score += self::WEIGHTS['variety'] * max(0.0, 1.0 - 0.5 * $sameCategory);

        // Weather fit
        $weatherFit = $candidate->outdoor && $context->rainExpected ? 0.0 : 1.0;
        $score += self::WEIGHTS['weather'] * $weatherFit;

        // Closes soon: a venue closing within 2h of the cursor gets a boost,
        // one already closed gets nothing (feasibility should have caught it).
        if ($candidate->closesAt !== null) {
            $minutesToClose = $cursor->diffInMinutes($candidate->closesAt, false);
            if ($minutesToClose > 0 && $minutesToClose <= 120) {
                $score += self::WEIGHTS['closes_soon'] * (1.0 - $minutesToClose / 120.0);
            }
        }

        // Preferred areas
        if ($candidate->veedel !== null
            && in_array($candidate->veedel, $context->preferredAreas, true)) {
            $score += self::WEIGHTS['area_match'];
        }

        // Intent signals (category × veedel weighted counts, normalised upstream)
        $intentKey = "{$candidate->category}|{$candidate->veedel}";
        $score += self::WEIGHTS['intent'] * min(1.0, $context->intentWeights[$intentKey] ?? 0.0);

        return round($score, 2);
    }
}
