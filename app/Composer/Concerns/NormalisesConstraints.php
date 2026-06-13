<?php

namespace App\Composer\Concerns;

use App\Composer\Constraints;
use App\Profile\Profile;
use Carbon\CarbonImmutable;

/**
 * Scope guard shared by every parser driver: clamp a parsed window to the
 * 72h horizon, never start in the past, and fall back to a sensible
 * profile-default day when the parse is unusable. Keeping this here means
 * the heuristic and the LLM driver enforce identical bounds.
 */
trait NormalisesConstraints
{
    private function clampConstraints(Constraints $constraints, Profile $profile, CarbonImmutable $now): Constraints
    {
        $horizon = $now->addHours(72);
        $start = $constraints->windowStart->max($now);
        $end = $constraints->windowEnd->min($horizon);

        if ($end->lessThanOrEqualTo($start)) {
            return $this->defaultConstraints($profile, $now);
        }

        return new Constraints(
            windowStart: $start,
            windowEnd: $end,
            areas: $constraints->areas !== [] ? $constraints->areas : $profile->defaultAreas,
            categories: $constraints->categories,
            companions: $constraints->companions,
            budget: $constraints->budget,
        );
    }

    private function defaultConstraints(Profile $profile, CarbonImmutable $now): Constraints
    {
        return new Constraints(
            windowStart: $now,
            windowEnd: $now->endOfDay()->min($now->addHours(8)),
            areas: $profile->defaultAreas,
        );
    }
}
