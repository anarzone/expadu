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
    /**
     * Smallest window the composer can actually fill: a daypart clamped to
     * "now" late in the day (e.g. "afternoon" at 17:00 → 17:00–18:00) is too
     * short to fit any activity plus travel, and would dead-end at "nothing
     * fits". When that happens we stretch the end forward so there is room.
     */
    private const MIN_WINDOW_MINUTES = 180;

    private function clampConstraints(Constraints $constraints, Profile $profile, CarbonImmutable $now): Constraints
    {
        $horizon = $now->addHours(72);

        // A fully-past named window — "today" / "this afternoon" asked once the
        // day is spent — rolls forward whole days, so the daypart is preserved
        // (an afternoon becomes tomorrow afternoon) instead of collapsing to an
        // empty late-night sliver. Bounded by the horizon.
        $start = $constraints->windowStart;
        $end = $constraints->windowEnd;
        while ($end->lessThanOrEqualTo($now) && $start->lessThan($horizon)) {
            $start = $start->addDay();
            $end = $end->addDay();
        }

        $start = $this->roundToQuarter($start->max($now), up: true);
        $end = $this->roundToQuarter($end->min($horizon), up: false);

        if ($end->lessThanOrEqualTo($start)) {
            // The named day sits beyond the 72h horizon (e.g. "Sunday" six days
            // out): plan from now instead of giving up — keeping the categories.
            $start = $this->roundToQuarter($now, up: true);
            $end = $start->addMinutes(self::MIN_WINDOW_MINUTES)->min($horizon);
        } elseif ($start->diffInMinutes($end) < self::MIN_WINDOW_MINUTES) {
            // A daypart clamped to "now" late in its span (afternoon at 17:00 →
            // 17:00–18:00) is too short to fit any activity plus travel: extend
            // the end so the plan isn't starved of time.
            $end = $start->addMinutes(self::MIN_WINDOW_MINUTES)->min($horizon);
        }

        return new Constraints(
            windowStart: $start,
            windowEnd: $end,
            // Everything the user asked for survives the window rescue. Dropping
            // the categories here is what made "pitch today" at 22:00 compose
            // "anything" and then return nothing. Areas stay exactly as named;
            // when none were given we leave them empty rather than spelling out
            // the home Bezirk — compose falls back to the profile's areas for
            // scoring, so the plan is unchanged but the UI stays clean.
            areas: $constraints->areas,
            categories: $constraints->categories,
            companions: $constraints->companions,
            budget: $constraints->budget,
            archetype: $constraints->archetype,
            vibe: $constraints->vibe,
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
