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
        $start = $this->roundToQuarter($constraints->windowStart->max($now), up: true);
        $end = $this->roundToQuarter($constraints->windowEnd->min($horizon), up: false);

        if ($end->lessThanOrEqualTo($start)) {
            return $this->defaultConstraints($now);
        }

        return new Constraints(
            windowStart: $start,
            windowEnd: $end,
            // Areas stay exactly as the user named them. When none were named
            // we leave this empty rather than spelling out the whole home
            // Bezirk as chips — compose falls back to the profile's areas for
            // scoring internally, so the plan is unchanged but the UI is clean.
            areas: $constraints->areas,
            categories: $constraints->categories,
            companions: $constraints->companions,
            budget: $constraints->budget,
            archetype: $constraints->archetype,
            vibe: $constraints->vibe,
        );
    }

    private function defaultConstraints(CarbonImmutable $now): Constraints
    {
        return new Constraints(
            windowStart: $this->roundToQuarter($now, up: true),
            windowEnd: $this->roundToQuarter($now->endOfDay()->min($now->addHours(8)), up: false),
        );
    }

    /**
     * Snap a time to a 15-minute boundary so a window clamped to "now"
     * reads cleanly (15:52 → 16:00) instead of exposing the raw minute.
     */
    private function roundToQuarter(CarbonImmutable $time, bool $up): CarbonImmutable
    {
        $time = $time->startOfMinute();
        $mod = $time->minute % 15;

        if ($mod === 0) {
            return $time;
        }

        return $up ? $time->addMinutes(15 - $mod) : $time->subMinutes($mod);
    }
}
