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

    public function matchesBudget(Constraints $constraints, Candidate $candidate): bool
    {
        return match ($constraints->budget) {
            'free' => $candidate->costTier === 'free',
            'low' => in_array($candidate->costTier, ['free', 'low'], true),
            default => true,
        };
    }

    /** Fact constraints are applied before retrieval caps and again before scoring. */
    public function matchesDiscovery(Constraints $constraints, Candidate $candidate): bool
    {
        if (! $this->matchesBudget($constraints, $candidate)) {
            return false;
        }
        if ($constraints->radiusKm !== null
            && ($candidate->distanceKmFromOrigin === null || $candidate->distanceKmFromOrigin > $constraints->radiusKm)) {
            return false;
        }
        if ($constraints->activities !== []) {
            $facts = $candidate->placeFacts;
            if (array_intersect($constraints->activities, $facts['activities'] ?? []) === []
                || ($facts['access']['value'] ?? 'unknown') !== 'public'
                || ($facts['access']['status'] ?? 'unknown') !== 'known'
                || ($facts['access']['conditional'] ?? null) !== null) {
                return false;
            }
            foreach (['reservation', 'booking', 'reservation:conditional', 'booking:conditional', 'opening_hours:conditional'] as $key) {
                $fact = $facts['practical'][$key] ?? [];
                if (($fact['status'] ?? 'unknown') === 'conflicting'
                    || (isset($fact['value']) && ! in_array(mb_strtolower($fact['value']), ['no', 'optional', 'recommended'], true))) {
                    return false;
                }
            }
        }

        return true;
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
        if (! $this->matchesDiscovery($constraints, $candidate)) {
            return false;
        }

        // Category filter (empty = all), coarse buckets already expanded to fines.
        if ($allowedCategories !== []
            && ! in_array($candidate->category, $allowedCategories, true)) {
            return false;
        }

        // A day with kids never routes through a bar or a coworking space.
        if ($constraints->companions === 'kids'
            && in_array($candidate->category, ['bar', 'coworking'], true)) {
            return false;
        }

        // Fixed-time events must start inside the window with room to attend.
        if ($candidate->isFixedTime()) {
            return $candidate->fixedStart->greaterThanOrEqualTo($constraints->windowStart)
                && $candidate->fixedStart->addMinutes($candidate->typicalDurationMin)
                    ->lessThanOrEqualTo($constraints->windowEnd);
        }

        return $candidate->nextVisitStart($constraints->windowStart, $constraints->windowEnd) !== null;
    }
}
