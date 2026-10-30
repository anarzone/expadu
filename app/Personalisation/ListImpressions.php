<?php

namespace App\Personalisation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Every list a person is shown, to measure whether an order helps (personalisation plan, item
 * 14): the order the rules gave (`baseline`), the order shown with each item's layout (`shown`)
 * and the policy that put it so, `rule` or `haiku`, with its version. Item keys only, as
 * TodayRanker::candidates names them ("e12@1793440800", "p34"), so a position is its index.
 * Only for people who opted in, under the ledger's pseudonym; nothing is kept otherwise. The
 * same list again on the same day counts on its row (seen_count, last_seen_at). What was done
 * with an item ties back through the ledger: the same subject, the same day, a key in `shown`.
 * A failed write never fails the page.
 */
final class ListImpressions
{
    /**
     * @param  list<string>  $baseline
     * @param  list<array{key: string, layout?: string}>  $shown
     */
    public function record(User $user, string $surface, CarbonImmutable $at, string $policy, string $version, array $baseline, array $shown): void
    {
        if ($shown === [] || ! $user->personalises()) {
            return;
        }

        $list = json_encode($shown, JSON_THROW_ON_ERROR);
        $now = $at->toIso8601String();
        rescue(fn () => DB::statement(
            'insert into list_impressions (subject_key, surface, day, policy, version, list_hash, baseline, shown, seen_count, first_seen_at, last_seen_at)
             values (?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, 1, ?, ?)
             on conflict (subject_key, surface, day, list_hash)
             do update set seen_count = list_impressions.seen_count + 1, last_seen_at = excluded.last_seen_at',
            [Pseudonym::for($user), $surface, $at->setTimezone('Europe/Berlin')->toDateString(), $policy, $version,
                hash('sha256', "{$policy}\n{$version}\n{$list}"), json_encode($baseline, JSON_THROW_ON_ERROR), $list, $now, $now],
        ));
    }
}
