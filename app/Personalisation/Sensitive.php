<?php

namespace App\Personalisation;

use App\Enums\SpotCategory;
use Illuminate\Support\Facades\DB;

/**
 * What personalisation never learns from (owner, 29 Oct 2026; the consent page's "What it never
 * uses"): health services, places of worship, LGBTQ+ venues, and events mainly about religion,
 * politics, a union, health, or sexual orientation and gender identity (events.sensitive, set by
 * the event classifier). Nothing about them is recorded (SignalItems, SignalRecorder::topic), and
 * anything recorded before is dropped before each rebuild (drop()).
 */
final class Sensitive
{
    /** Health services and places of worship. A drugstore or a vet says nothing about someone's health. */
    public const SPOT_CATEGORIES = [
        SpotCategory::Pharmacy, SpotCategory::Doctor, SpotCategory::Dentist, SpotCategory::Optician, SpotCategory::HearingAids,
        SpotCategory::MedicalSupply, SpotCategory::Clinic, SpotCategory::Hospital, SpotCategory::PlaceOfWorship,
    ];

    /** @return list<string> */
    public static function spotCategories(): array
    {
        return array_map(fn (SpotCategory $category) => $category->value, self::SPOT_CATEGORIES);
    }

    /**
     * SQL true for a sensitive place row under `$alias`: its category, a place of worship tagged on
     * another category (a cathedral listed as an attraction), or an LGBTQ+ venue as OpenStreetMap
     * tags it (lgbtq=primary, only, welcome or yes; a centre for LGBTQ+ people).
     *
     * @return array{0: string, 1: list<string>} the condition and its bindings
     */
    public static function spotCondition(string $alias): array
    {
        $categories = self::spotCategories();
        $in = implode(', ', array_fill(0, count($categories), '?'));

        // Each part false, never null, when a place has no such tag: `not` of a null drops it too.
        return ["(coalesce({$alias}.category in ({$in}), false)
            or coalesce({$alias}.tags::jsonb ->> 'amenity', '') = 'place_of_worship'
            or coalesce({$alias}.tags::jsonb ->> 'lgbtq', 'no') not in ('no', '')
            or coalesce({$alias}.tags::jsonb ->> 'community_centre:for', '') ilike '%lgbt%')", $categories];
    }

    /** A Composer topic's kind that's a health service or a place of worship. */
    public static function kind(string $kind): bool
    {
        return in_array($kind, self::spotCategories(), true);
    }

    /** Drops what was recorded about them before this was in place; returns the rows dropped. */
    public static function drop(): int
    {
        [$spot, $bindings] = self::spotCondition('s');
        $categories = self::spotCategories();
        $in = implode(', ', array_fill(0, count($categories), '?'));

        $signals = DB::delete(
            "delete from user_signals u where
                (u.item_type = 'spot' and exists (select 1 from spots s where s.id = u.item_id and {$spot}))
                or (u.item_type = 'event' and exists (select 1 from events e where e.id = u.item_id and e.sensitive))
                or (u.item_type = 'topic' and u.category in ({$in}))",
            [...$bindings, ...$categories],
        );
        $negatives = DB::delete(
            "delete from user_place_negatives u where
                (u.item_type = 'spot' and exists (select 1 from spots s where s.id = u.item_id and {$spot}))
                or (u.item_type = 'event' and exists (select 1 from events e where e.id = u.item_id and e.sensitive))",
            $bindings,
        );

        return $signals + $negatives;
    }
}
