<?php

namespace App\Personalisation;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the aggregates from the ledger in set-based SQL, inside one
 * transaction: readers see the old aggregates until the new ones commit, and
 * the same ledger and clock always give the same result (idempotent).
 *
 * A signal of weight w that is t days old counts w × 0.5^(t / half-life):
 * tastes decay on the taste half-life, place negatives on their own.
 */
final class AffinityAggregator
{
    /** @return array{affinities: int, place_negatives: int} rows written */
    public function rebuild(?CarbonInterface $now = null): array
    {
        $now = ($now ?? now())->toImmutable();
        [$signals, $bindings] = $this->signals($now);
        $contexts = $this->contexts();

        return DB::transaction(function () use ($signals, $bindings, $contexts, $now): array {
            DB::table('user_affinities')->delete();
            $affinities = DB::affectingStatement(
                "with signals as ({$signals})
                 insert into user_affinities (subject_key, dimension, key, context, score, signals_count, updated_at)
                 select s.subject_key, d.dimension, d.key, c.context,
                        sum(s.weight * power(0.5, extract(epoch from (?::timestamptz - s.created_at)) / 86400.0 / ?)),
                        count(*), ?::timestamptz
                 from signals s
                 cross join lateral (values ('category', s.category), ('veedel', s.veedel), ('price_band', s.price_band), ('daypart', s.context->>'daypart')) as d(dimension, key)
                 cross join lateral ({$contexts}) as c(context)
                 where s.taste and d.key is not null and c.context is not null
                 group by s.subject_key, d.dimension, d.key, c.context",
                [...$bindings, $now->toIso8601String(), $this->halfLife('taste'), $now->toIso8601String()],
            );

            DB::table('user_place_negatives')->delete();
            $negatives = DB::affectingStatement(
                "with signals as ({$signals})
                 insert into user_place_negatives (subject_key, item_type, item_id, context, score, hidden_until, signals_count, updated_at)
                 select s.subject_key, s.item_type, s.item_id, c.context,
                        sum(s.weight * power(0.5, extract(epoch from (?::timestamptz - s.created_at)) / 86400.0 / ?)),
                        case when c.context = 'all' then max(s.created_at + make_interval(days => s.hide_days)) end,
                        count(*), ?::timestamptz
                 from signals s
                 cross join lateral ({$contexts}) as c(context)
                 where s.place and c.context is not null
                 group by s.subject_key, s.item_type, s.item_id, c.context",
                [...$bindings, $now->toIso8601String(), $this->halfLife('place_negative'), $now->toIso8601String()],
            );

            return ['affinities' => $affinities, 'place_negatives' => $negatives];
        });
    }

    /**
     * The weighted signals in the retention window: each action on an item
     * counted once per dedupe window, plus one ignored-impression signal for
     * every item shown N times (in N windows) that the person never touched.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function signals(CarbonInterface $now): array
    {
        $values = [];
        $bindings = [];
        foreach ((array) config('personalisation.actions') as $action => $rule) {
            $values[] = '(?::text, ?::float8, ?::boolean, ?::boolean, ?::int)';
            array_push($bindings, $action, (float) ($rule['weight'] ?? 0), (bool) ($rule['taste'] ?? false), (bool) ($rule['place'] ?? false), $rule['hide_days'] ?? null);
        }
        $weights = implode(', ', $values);
        $window = max(1, (int) config('personalisation.dedupe_minutes', 60)) * 60;
        $since = $now->subDays((int) config('personalisation.retention_days', 90))->toIso8601String();
        $ignored = config('personalisation.ignored_impressions');

        $sql = "with weights(action, weight, taste, place, hide_days) as (values {$weights}),
            deduped as (
                select distinct on (subject_key, action, item_type, item_id, floor(extract(epoch from created_at) / {$window}))
                       subject_key, action, item_type, item_id, category, veedel, price_band, context, created_at
                from user_signals
                -- Catalogue items only: topics, stops, lines and Today's needs are read by TodayTaste.
                where created_at >= ?::timestamptz and item_type in ('spot', 'event')
                order by subject_key, action, item_type, item_id, floor(extract(epoch from created_at) / {$window}), created_at
            ),
            showings as (
                select d.*, row_number() over (partition by subject_key, item_type, item_id order by created_at) as showing
                from deduped d where action = 'impression'
            )
            select d.subject_key, d.item_type, d.item_id, d.category, d.veedel, d.price_band, d.context, d.created_at,
                   w.weight, w.taste, w.place, w.hide_days
            from deduped d join weights w on w.action = d.action
            where w.weight <> 0 or w.hide_days is not null
            union all
            select s.subject_key, s.item_type, s.item_id, s.category, s.veedel, s.price_band, s.context, s.created_at,
                   ?::float8, true, true, null::int
            from showings s
            where s.showing = ?
              and not exists (
                  select 1 from deduped t
                  where t.subject_key = s.subject_key and t.item_type = s.item_type and t.item_id = s.item_id and t.action <> 'impression'
              )";

        return [$sql, [...$bindings, $since, (float) ($ignored['weight'] ?? -0.3), max(1, (int) ($ignored['showings'] ?? 3))]];
    }

    /** "all" plus one context per configured field the signal carries. */
    private function contexts(): string
    {
        $fields = array_filter((array) config('personalisation.contexts'), fn ($field) => is_string($field) && preg_match('/^[a-z_]+$/', $field));
        $rows = ["('all')"];
        foreach ($fields as $field) {
            // week values (weekday/weekend) stand alone; others carry their field name.
            $rows[] = $field === 'week' ? "(s.context->>'week')" : "('{$field}:' || (s.context->>'{$field}'))";
        }

        return 'values '.implode(', ', $rows);
    }

    private function halfLife(string $kind): float
    {
        return max(1.0, (float) config("personalisation.half_life_days.{$kind}"));
    }
}
