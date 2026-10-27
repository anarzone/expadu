<?php

namespace App\Personalisation;

use App\Models\User;

/**
 * What a person agreed to when they turned personalisation on, by the wording's version: a yes
 * under an older wording keeps what that wording said and no more, until they turn it on again
 * under this one (Settings shows when that's due: personalisation_current).
 * 1 (21 Oct 2026): the places and events they open, save, travel to or turn down.
 * 2 (owner, 26 Oct 2026: "Go as proposed", "AI should be used for personalization"): also Today's
 *   alerts, Travel's stops and lines and Composer's topics they open, search, save, skip or
 *   dismiss; and Today put in order by Claude Haiku from what was learned (TodayRank).
 */
final class PersonalisationWording
{
    public const CURRENT = 2;

    /** Where the version they agreed to is kept with their settings; set by the server, never by a request. */
    public const SETTING = 'personalisation_wording';

    /** The wording they agreed to: 0 when it's off; a yes from before versions is the first. */
    public static function of(User $user): int
    {
        return $user->personalises() ? max(1, (int) ($user->setting(self::SETTING) ?? 1)) : 0;
    }

    /** On under at least this wording. */
    public static function covers(User $user, int $version): bool
    {
        return self::of($user) >= $version;
    }

    /** Nothing to confirm: off, or on under the current wording. */
    public static function current(User $user): bool
    {
        return self::of($user) === 0 || self::of($user) >= self::CURRENT;
    }

    /**
     * The settings as they're saved: the version from the server alone, the current one when they
     * turn it on, none when it's off.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $merged
     * @param  array<string, mixed>  $sent
     * @return array<string, mixed>
     */
    public static function saved(array $before, array $merged, array $sent): array
    {
        $merged[self::SETTING] = ($sent['personalisation'] ?? null) === true ? self::CURRENT : ($before[self::SETTING] ?? null);
        if (($merged['personalisation'] ?? false) !== true || $merged[self::SETTING] === null) {
            unset($merged[self::SETTING]);
        }

        return $merged;
    }
}
