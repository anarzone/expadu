<?php

namespace App\Composer;

/**
 * Hard constraints only — deletes ~95% of candidates before any scoring.
 * A candidate survives if it is open during the window, fits the
 * remaining time, matches the budget, and (for events) starts inside
 * the window.
 */
class FeasibilityFilter
{
    /**
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    public function filter(Constraints $constraints, array $candidates): array
    {
        return array_values(array_filter(
            $candidates,
            fn (Candidate $candidate) => $this->fits($constraints, $candidate),
        ));
    }

    private function fits(Constraints $constraints, Candidate $candidate): bool
    {
        // Budget: a "free" plan excludes anything that costs money.
        if ($constraints->budget === 'free' && $candidate->costTier !== 'free') {
            return false;
        }
        if ($constraints->budget === 'low' && $candidate->costTier === 'normal') {
            return false;
        }

        // Category filter (empty = all)
        if ($constraints->categories !== []
            && ! in_array($candidate->category, $constraints->categories, true)) {
            return false;
        }

        // A day with kids never routes through a bar.
        if ($constraints->companions === 'kids' && $candidate->category === 'bar') {
            return false;
        }

        // Fixed-time events must start inside the window with room to attend.
        if ($candidate->isFixedTime()) {
            return $candidate->fixedStart->greaterThanOrEqualTo($constraints->windowStart)
                && $candidate->fixedStart->addMinutes($candidate->typicalDurationMin)
                    ->lessThanOrEqualTo($constraints->windowEnd);
        }

        // Venue must be open for at least its typical duration inside the window.
        $visitStart = $constraints->windowStart;
        if ($candidate->opensAt !== null && $candidate->opensAt->greaterThan($visitStart)) {
            $visitStart = $candidate->opensAt;
        }

        $visitEnd = $constraints->windowEnd;
        if ($candidate->closesAt !== null && $candidate->closesAt->lessThan($visitEnd)) {
            $visitEnd = $candidate->closesAt;
        }

        return $visitStart->diffInMinutes($visitEnd, false) >= $candidate->typicalDurationMin;
    }
}
