<?php

namespace App\Composer;

use App\Enums\SpotCategory;

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
        $allowedCategories = $this->allowedCategories($constraints->categories);

        return array_values(array_filter(
            $candidates,
            fn (Candidate $candidate) => $this->fits($constraints, $candidate, $allowedCategories),
        ));
    }

    /**
     * Expand the requested categories to the fine values a candidate actually
     * carries. A request may name a coarse bucket ("culture") or a fine
     * category ("museum"); candidates are always fine, so a coarse bucket is
     * expanded to its fines and a fine value matches itself. Empty in = no
     * filter (all categories allowed).
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function allowedCategories(array $requested): array
    {
        $allowed = [];
        foreach ($requested as $category) {
            $allowed[] = $category; // a fine value matches itself
            foreach (SpotCategory::finesForCoarse($category) as $fine) {
                $allowed[] = $fine; // a coarse bucket matches all its fines
            }
        }

        return array_values(array_unique($allowed));
    }

    /**
     * @param  list<string>  $allowedCategories  pre-expanded fine categories; empty = all
     */
    private function fits(Constraints $constraints, Candidate $candidate, array $allowedCategories): bool
    {
        // Budget: a "free" plan excludes anything that costs money.
        if ($constraints->budget === 'free' && $candidate->costTier !== 'free') {
            return false;
        }
        if ($constraints->budget === 'low' && $candidate->costTier === 'normal') {
            return false;
        }

        // Category filter (empty = all), coarse buckets already expanded to fines.
        if ($allowedCategories !== []
            && ! in_array($candidate->category, $allowedCategories, true)) {
            return false;
        }

        // A day with kids never routes through a bar.
        if ($constraints->companions === 'kids' && $candidate->category === 'bar') {
            return false;
        }

        // Real opening hours say the venue is shut on the plan's day.
        if (! $candidate->isFixedTime() && $candidate->closedToday) {
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
