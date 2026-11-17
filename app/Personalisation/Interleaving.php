<?php

namespace App\Personalisation;

use App\Models\User;

/**
 * Does the order help? (the interleaving canvas; owner 17 Nov 2026: "two orders, opens only, build
 * it now"). With it on (personalisation.interleave), a person's Today ideas are one list drafted
 * from two orders of the same ideas, the usual one and theirs: each turn the order with fewer
 * picks so far adds its best idea not yet in; when they're level, a coin fixed for that person and
 * day decides (team-draft interleaving). Each idea keeps which order put it there (`team`), logged
 * with the list (ListImpressions) and never shown. WeeklyMeasure counts opens by team: a day goes
 * to the order whose ideas they opened more, and each person gets one vote.
 */
final class Interleaving
{
    public const USUAL = 'usual';

    public const YOURS = 'you';

    /** Lists whose first few are the same both ways decide nothing: they're shown as they are. */
    private const DECIDES_WITHIN = 4;

    public static function on(User $user): bool
    {
        return (bool) config('personalisation.interleave', false) && $user->personalises();
    }

    /**
     * The two orders drafted into one, each key with the order that put it there; null when the
     * first few are the same both ways.
     *
     * @param  list<string>  $usual
     * @param  list<string>  $yours
     * @return list<array{key: string, team: string}>|null
     */
    public static function draft(array $usual, array $yours, string $seed): ?array
    {
        if (array_slice($usual, 0, self::DECIDES_WITHIN) === array_slice($yours, 0, self::DECIDES_WITHIN)) {
            return null;
        }
        $orders = [self::USUAL => array_values($usual), self::YOURS => array_values($yours)];
        $all = array_values(array_unique([...$usual, ...$yours]));
        [$merged, $in, $count, $turn] = [[], [], [self::USUAL => 0, self::YOURS => 0], 0];
        while (count($merged) < count($all)) {
            $level = $count[self::USUAL] === $count[self::YOURS];
            $team = $level
                ? (hexdec(substr(hash('sha256', "{$seed}|{$turn}"), 0, 8)) % 2 === 0 ? self::USUAL : self::YOURS)
                : ($count[self::USUAL] < $count[self::YOURS] ? self::USUAL : self::YOURS);
            $next = null;
            foreach ($orders[$team] as $key) {
                if (! isset($in[$key])) {
                    $next = $key;
                    break;
                }
            }
            // That order has nothing left: the other one adds.
            if ($next === null) {
                $team = $team === self::USUAL ? self::YOURS : self::USUAL;
                foreach ($orders[$team] as $key) {
                    if (! isset($in[$key])) {
                        $next = $key;
                        break;
                    }
                }
            }
            $merged[] = ['key' => $next, 'team' => $team];
            $in[$next] = true;
            $count[$team]++;
            $turn++;
        }

        return $merged;
    }

    /** The coin's seed: the same person and day give the same draft. */
    public static function seed(User $user, string $day): string
    {
        return Pseudonym::for($user).'|'.$day;
    }
}
