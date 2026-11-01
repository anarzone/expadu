<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The other feeds' listings that are attractions, not events (owner, 28 Oct 2026): a museum's
 * exhibition or a place's opening hours listed day by day are a place to visit, never "on now"
 * on Today or in What's on. koeln.de and the city list them one row per day; DatenHub's own
 * import leaves them out already (ImportDatenHubEvents).
 *
 * An attraction is a listing koeln.de files under exhibitions, or the same listing at the same
 * place on five days or more, six hours or more a day from the morning or midday. A festival or
 * a fair keeps its days, and so does anything wholly in the winter season (1 November to the
 * end of February: Christmas markets, light trails, ice rinks).
 */
final class EventAttractions
{
    private const SOURCES = ['koeln.de', 'stadt-koeln.de'];

    /** The fewest days a listing's daily opening hours show up on. */
    private const DAYS = 5;

    /**
     * Their ids, worked out once an hour.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return Cache::remember('events:attractions', now()->addHour(), function (): array {
            $exhibitions = DB::table('events')
                ->whereIn('source', self::SOURCES)
                ->where('status', 'active')
                ->whereRaw("tags::jsonb @> '[\"Ausstellungen\"]'::jsonb")
                ->pluck('id');

            $openingHours = DB::table('events')
                ->whereIn('source', self::SOURCES)
                ->where('status', 'active')
                ->whereNull('recurrence')
                ->whereRaw("ends_at - starts_at >= interval '6 hours'")
                // Stored in the app's own time (Europe/Berlin): the hour as listed.
                ->whereRaw('extract(hour from starts_at) < 13')
                ->whereRaw("title !~* '(festival|messe|fair)'")
                ->groupByRaw('lower(title), coalesce(venue_id::text, location_name)')
                ->havingRaw('count(distinct starts_at::date) >= ?', [self::DAYS])
                ->selectRaw('array_to_json(array_agg(id)) AS ids, min(starts_at) AS first, max(starts_at) AS last')
                ->get()
                ->reject(fn (object $run) => self::winter(CarbonImmutable::parse($run->first), CarbonImmutable::parse($run->last)))
                ->flatMap(fn (object $run) => json_decode($run->ids, true));

            return $exhibitions->merge($openingHours)->map(fn ($id) => (int) $id)->unique()->values()->all();
        });
    }

    /** Whether a run lies wholly in one winter season (1 November to the end of February). */
    private static function winter(CarbonImmutable $first, CarbonImmutable $last): bool
    {
        $from = $first->setDate($first->month >= 11 ? $first->year : $first->year - 1, 11, 1)->startOfDay();

        return $last->lessThanOrEqualTo($from->setDate($from->year + 1, 2, 1)->endOfMonth());
    }
}
