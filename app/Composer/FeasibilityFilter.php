<?php

namespace App\Composer;

use App\Enums\SpotCategory;
use App\Places\PlaceAmenities;
use Carbon\CarbonImmutable;

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

    /** Validate a person's actual interval, which may differ from the default visit duration. */
    public function matchesSelection(Constraints $constraints, Candidate $candidate, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->matchesDiscovery($constraints, $candidate)
            && $this->matchesCategoryAndCompanions($constraints, $candidate, $this->allowedCategories($constraints->categories))
            && $start->greaterThanOrEqualTo($constraints->windowStart)
            && $end->lessThanOrEqualTo($constraints->windowEnd)
            && $end->greaterThan($start);
    }

    /** Fact constraints are applied before retrieval caps and again before scoring. */
    public function matchesDiscovery(Constraints $constraints, Candidate $candidate): bool
    {
        if (! $this->matchesBudget($constraints, $candidate)) {
            return false;
        }
        if ($candidate->type === 'spot'
            && (in_array($candidate->sourceFacts['access_status'] ?? null, ['restricted', 'private', 'conditional', 'conflicting'], true)
                || ($candidate->sourceFacts['requires_arrangement'] ?? false))) {
            return false;
        }
        if ($constraints->budget === 'free' && $candidate->type === 'spot'
            && in_array($candidate->category, ['pitch', 'basketball', 'tennis', 'table_tennis', 'boules', 'sports_centre'], true)
            && ! in_array($candidate->sourceFacts['access_status'] ?? $candidate->access, ['public', 'permissive'], true)) {
            return false;
        }
        if ($constraints->radiusKm !== null
            && ($candidate->distanceKmFromOrigin === null || $candidate->distanceKmFromOrigin > $constraints->radiusKm)) {
            return false;
        }
        // A hard need is met only by a verified fact; an unknown never passes.
        if (in_array('step_free', $constraints->needs, true) && ! $candidate->isAppointment() && ! $candidate->stepFree()) {
            return false;
        }
        foreach ($constraints->features as $feature) {
            if ($candidate->type !== 'spot' || ! PlaceAmenities::has($candidate->placeFacts['practical'] ?? [], $feature)) {
                return false;
            }
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

        if (! $this->matchesCategoryAndCompanions($constraints, $candidate, $allowedCategories)) {
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

    private function matchesCategoryAndCompanions(Constraints $constraints, Candidate $candidate, array $allowedCategories): bool
    {
        return ($allowedCategories === [] || in_array($candidate->category, $allowedCategories, true)
                // "event" asks for events of any kind; theirs are event categories, not place ones.
                || ($candidate->type === 'event' && in_array('event', $allowedCategories, true)))
            && ! in_array($candidate->category, $this->allowedCategories($constraints->avoid), true)
            && ($constraints->companions !== 'kids' || ! in_array($candidate->category, ['bar', 'coworking'], true));
    }
}
