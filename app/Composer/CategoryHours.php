<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * What a category's day actually looks like, used two ways:
 *
 * 1. defaults() — typical open/close hours applied ONLY when a spot has no
 *    real opening_hours. Unknown used to mean "always open", which put
 *    museums in 22:00 plans and playgrounds after dark. Real hours always
 *    win; these are marked assumed so the UI never claims them as fact.
 * 2. dayFit() — a soft 0..1 "does this belong at this hour" signal for the
 *    scorer: restaurants peak at meal times, bars in the evening, parks in
 *    daylight. Feasibility draws the hard line; this shapes preference.
 */
class CategoryHours
{
    /** Daylight-bound outdoor categories — usable from morning until dusk. */
    private const DAYLIGHT = [
        'park', 'playground', 'pitch', 'basketball', 'tennis', 'skatepark',
        'lake', 'dog_park', 'table_tennis', 'boules', 'bbq', 'picnic',
    ];

    /**
     * Typical open/close as "H:MM" strings; close past midnight is expressed
     * >= 24. Categories not listed (and viewpoints — a city view is fine at
     * night) carry no default and stay always-open.
     */
    private const HOURS = [
        'museum' => ['10:00', '18:00'],
        'gallery' => ['11:00', '18:00'],
        'attraction' => ['10:00', '19:00'],
        'zoo' => ['9:00', '18:00'],
        'library' => ['10:00', '19:00'],
        'coworking' => ['8:00', '20:00'],
        'cafe' => ['8:00', '19:00'],
        'bakery' => ['7:00', '18:00'],
        'restaurant' => ['11:30', '23:00'],
        'fast_food' => ['11:00', '23:30'],
        'bar' => ['17:00', '25:00'],
        'swimming' => ['9:00', '22:00'],
    ];

    /**
     * Soft fitness per daypart: [morning, midday, afternoon, evening, night].
     * Morning 6–11:30 · midday 11:30–14 · afternoon 14–17:30 · evening
     * 17:30–21 · night 21–6.
     */
    private const FIT = [
        'restaurant' => [0.2, 1.0, 0.4, 1.0, 0.6],
        'fast_food' => [0.3, 0.9, 0.6, 0.9, 0.8],
        'cafe' => [1.0, 0.9, 0.9, 0.5, 0.1],
        'bakery' => [1.0, 0.8, 0.6, 0.2, 0.0],
        'bar' => [0.0, 0.1, 0.3, 1.0, 1.0],
        'museum' => [0.9, 0.8, 1.0, 0.4, 0.1],
        'gallery' => [0.9, 0.8, 1.0, 0.4, 0.1],
        'zoo' => [0.9, 0.9, 1.0, 0.5, 0.2],
        'attraction' => [0.9, 0.9, 1.0, 0.6, 0.3],
        'library' => [0.9, 0.8, 0.9, 0.5, 0.1],
        'coworking' => [0.9, 0.8, 0.9, 0.5, 0.1],
        'viewpoint' => [0.6, 0.6, 0.8, 1.0, 1.0],
        'swimming' => [0.7, 0.8, 1.0, 0.9, 0.3],
        // Kid infrastructure — great by day, not an adult evening pick. A
        // "with the kids" day boosts these via affinity regardless of hour.
        'playground' => [0.9, 0.9, 1.0, 0.4, 0.1],
    ];

    private const FIT_DAYLIGHT = [0.9, 0.9, 1.0, 0.8, 0.2];

    private const FIT_DEFAULT = [0.7, 0.7, 0.7, 0.7, 0.5];

    /**
     * Typical open/close for a category on a given day, or [null, null] when
     * we hold no opinion (events, viewpoints, unknown categories).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function defaults(string $category, CarbonImmutable $day): array
    {
        if (in_array($category, self::DAYLIGHT, true)) {
            return [$day->setTime(8, 0), self::dusk($day)];
        }

        $hours = self::HOURS[$category] ?? null;
        if ($hours === null) {
            return [null, null];
        }

        return [self::at($day, $hours[0]), self::at($day, $hours[1])];
    }

    /** How well a category fits the given time of day, 0..1. */
    public static function dayFit(string $category, CarbonImmutable $at): float
    {
        $fit = self::FIT[$category]
            ?? (in_array($category, self::DAYLIGHT, true) ? self::FIT_DAYLIGHT : self::FIT_DEFAULT);

        return $fit[self::band($at)];
    }

    /** When outdoor daylight ends in Cologne, by season — coarse on purpose. */
    private static function dusk(CarbonImmutable $day): CarbonImmutable
    {
        return match ($day->month) {
            11, 12, 1, 2 => $day->setTime(17, 30),
            3, 10 => $day->setTime(19, 0),
            4, 9 => $day->setTime(20, 30),
            default => $day->setTime(21, 45), // May–Aug
        };
    }

    private static function at(CarbonImmutable $day, string $hhmm): CarbonImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return $h >= 24 ? $day->addDay()->setTime($h - 24, $m) : $day->setTime($h, $m);
    }

    private static function band(CarbonImmutable $at): int
    {
        $minutes = $at->hour * 60 + $at->minute;

        return match (true) {
            $minutes < 6 * 60 => 4,            // night
            $minutes < 11 * 60 + 30 => 0,      // morning
            $minutes < 14 * 60 => 1,           // midday
            $minutes < 17 * 60 + 30 => 2,      // afternoon
            $minutes < 21 * 60 => 3,           // evening
            default => 4,                      // night
        };
    }
}
