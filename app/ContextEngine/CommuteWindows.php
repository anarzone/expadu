<?php

namespace App\ContextEngine;

use App\Models\User;
use App\Models\UserPlace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a person is on their way, for the pushes that matter only then (a delay, the weather):
 * the two hours before they're due at Home or Work, from its arrive-by on its own days
 * (coordinator, 26 Oct 2026). A part of the day they've told us nothing about keeps the old
 * fixed window (6–9 in the morning, 16–19 in the evening). A time to leave work comes with
 * Settings (phase 3).
 */
final class CommuteWindows
{
    /** The way there: from two hours before the arrive-by until just after it. */
    private const BEFORE_MINUTES = 120;

    private const AFTER_MINUTES = 10;

    /** The old windows, hours inclusive (06:00–09:59, 16:00–19:59), for a morning or an evening with no arrive-by. */
    private const MORNING = [6, 9];

    private const EVENING = [16, 19];

    /** An arrive-by before this is a morning one; from it on, an evening one. */
    private const NOON = 12;

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}> the windows on that day, Berlin time
     */
    public static function on(User $user, CarbonInterface $day): array
    {
        $day = CarbonImmutable::instance($day)->setTimezone('Europe/Berlin')->startOfDay();
        $windows = UserPlace::query()->where('user_id', $user->id)->whereIn('category', ['home', 'work'])->whereNotNull('arrive_by')->get()
            ->filter(fn (UserPlace $place): bool => $place->isActiveOn($day))
            ->map(function (UserPlace $place) use ($day): array {
                $due = $day->setTimeFromTimeString((string) $place->arrive_by);

                return [$due->subMinutes(self::BEFORE_MINUTES), $due->addMinutes(self::AFTER_MINUTES)];
            })->values()->all();
        $morning = collect($windows)->contains(fn (array $w): bool => $w[1]->hour < self::NOON);
        $evening = collect($windows)->contains(fn (array $w): bool => $w[1]->hour >= self::NOON);

        return [
            ...$windows,
            ...($morning ? [] : [[$day->setTime(self::MORNING[0], 0), $day->setTime(self::MORNING[1], 59, 59)]]),
            ...($evening ? [] : [[$day->setTime(self::EVENING[0], 0), $day->setTime(self::EVENING[1], 59, 59)]]),
        ];
    }

    /** Whether this moment is on one of their ways. */
    public static function contains(User $user, CarbonInterface $at): bool
    {
        $at = CarbonImmutable::instance($at)->setTimezone('Europe/Berlin');

        return collect(self::on($user, $at))->contains(fn (array $w): bool => $at->betweenIncluded($w[0], $w[1]));
    }
}
