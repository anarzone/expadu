<?php

namespace App\Home;

use Carbon\CarbonImmutable;

/**
 * Deterministic copy bank for the discovery rails. The lead "ranked for you"
 * rail's title reflects its strongest live signal — rain (its picks genuinely
 * skew indoor), then the weekend/evening window, then a just-arrived user, then
 * a plain weekday — and rotates through a few phrasings keyed on the day, so it
 * reads fresh across the week and differs by who you are without an LLM. Pure +
 * deterministic: fully testable, never hallucinates, no cost on the hot path.
 */
class RailCopy
{
    /**
     * Title + subline for the main "ranked for you" rail, chosen from the
     * strongest live signal and varied by weekday.
     *
     * @return array{0: string, 1: string} [title, subline]
     */
    public static function leadRail(
        CarbonImmutable $now,
        bool $rain,
        bool $isWeekend,
        bool $isEvening,
        bool $isNewArrival,
    ): array {
        $day = $now->format('l');

        if ($rain) {
            return [
                self::rotate($now, [
                    "Indoor picks for a rainy {$day}",
                    "Stay dry this {$day}",
                    "Rainy {$day}? Keep it cosy",
                ]),
                'indoor picks, ranked for you',
            ];
        }

        if ($isWeekend) {
            return [
                self::rotate($now, [
                    "Make the most of your {$day}",
                    "Your {$day} in Cologne",
                    "{$day}, sorted",
                ]),
                'ranked for your weekend',
            ];
        }

        if ($isEvening) {
            return [
                self::rotate($now, [
                    "Wind down your {$day}",
                    "Round off your {$day}",
                ]),
                'good for this evening',
            ];
        }

        if ($isNewArrival) {
            return [
                self::rotate($now, [
                    'Find your feet in Cologne',
                    'Settling into Cologne',
                ]),
                'a gentle intro to the city',
            ];
        }

        return [
            self::rotate($now, [
                "Made for your {$day}",
                "Picked for your {$day}",
                "Your {$day}, sorted",
            ]),
            'ranked for you',
        ];
    }

    /**
     * Pick one phrasing deterministically by the day of week — stable within a
     * day, fresh across the week, never random (so it's testable).
     *
     * @param  list<string>  $variants
     */
    private static function rotate(CarbonImmutable $now, array $variants): string
    {
        return $variants[$now->dayOfWeek % count($variants)];
    }
}
