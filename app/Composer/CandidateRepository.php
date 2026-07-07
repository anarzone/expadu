<?php

namespace App\Composer;

use App\Models\Event;
use App\Models\Spot;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The impure boundary of the composer: snapshots spots and curated
 * events into Candidates so every later stage is a pure function.
 * Candidates are capped — the feasibility filter and scorer are O(n)
 * per slot and the window never needs hundreds of options.
 */
class CandidateRepository
{
    private const MAX_CANDIDATES = 200;

    /** Nearest spots kept per category — diverse enough for a 6-slot day, local enough to be relevant. */
    private const PER_CATEGORY = 12;

    /** Cologne centre — the origin a plan falls back to when the user has no location. */
    private const COLOGNE_LAT = 50.9375;

    private const COLOGNE_LNG = 6.9603;

    private const OUTDOOR_CATEGORIES = ['park', 'playground', 'pitch', 'basketball', 'tennis', 'table_tennis', 'boules', 'lake', 'dog_park', 'bbq', 'picnic', 'viewpoint', 'skatepark'];

    private const DEFAULT_DURATION_MIN = [
        'park' => 75,
        'cafe' => 60,
        'library' => 90,
        'coworking' => 120,
        'restaurant' => 75,
        'bar' => 90,
        'default' => 60,
    ];

    /**
     * @return list<Candidate>
     */
    public function candidatesFor(Constraints $constraints, float $originLat = self::COLOGNE_LAT, float $originLng = self::COLOGNE_LNG): array
    {
        $events = $this->eventCandidates($constraints);
        $spots = $this->spotCandidates($constraints->windowStart, $originLat, $originLng);

        // Events get a RESERVED slice, not the leftovers. At prod volume ~20
        // categories each contribute their dozen nearest spots — well over
        // MAX_CANDIDATES on their own — so a plain merge-then-slice truncated
        // every event away and curated events could never reach a plan. Events
        // are few (the query caps them at 50) and worth building a day around,
        // so they keep their room; the rest of the budget is the nearest spots,
        // as before. The total still respects MAX_CANDIDATES for the O(n) stages.
        $spotBudget = max(self::MAX_CANDIDATES - count($events), 0);

        return [
            ...array_slice($spots, 0, $spotBudget),
            ...$events,
        ];
    }

    /**
     * A diverse, bounded, LOCAL pool: the nearest ~12 per category to the plan's
     * origin. Per-category keeps it varied (2,000 playgrounds can't drown out
     * the museums and cafés); nearest-first makes it reflect where the day
     * actually starts — so the spots near the user surface, instead of 12
     * arbitrary lowest-id rows from across the city. Rating no longer drives
     * selection (only commercial venues are rated, so it buried every free
     * outdoor spot); the scorer still ranks quality within the pool.
     *
     * @return list<Candidate>
     */
    private function spotCandidates(CarbonImmutable $day, float $originLat, float $originLng): array
    {
        // The shared great-circle formula (single source in NearbyPlaces), used
        // here inside a per-category ROW_NUMBER window the service can't express.
        $distance = NearbyPlaces::DISTANCE_KM_SQL;

        $ranked = DB::table('spots')
            ->select('id')
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY category ORDER BY ({$distance}), id) AS rn", NearbyPlaces::bindings($originLat, $originLng))
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        $ids = DB::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', '<=', self::PER_CATEGORY)
            ->pluck('id')
            ->all();

        if ($ids === []) {
            return [];
        }

        // Return globally nearest-first so the MAX_CANDIDATES cap keeps the
        // closest spots when many categories each contribute their dozen.
        return Spot::query()
            ->whereIn('id', $ids)
            ->orderByRaw("({$distance}), id", NearbyPlaces::bindings($originLat, $originLng))
            ->get()
            ->map(fn (Spot $spot) => $this->spotToCandidate($spot, $day))
            ->all();
    }

    /**
     * Load specific spots by candidate id ("spot:12") regardless of rating —
     * so a "plan around this" pin from the home feed is guaranteed to be in
     * the pool even if it falls outside the top-rated cap.
     *
     * @param  list<string>  $ids
     * @return list<Candidate>
     */
    public function byIds(array $ids, CarbonImmutable $day): array
    {
        $spotIds = collect($ids)
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'spot:'))
            ->map(fn ($id) => (int) substr($id, 5))
            ->filter()
            ->all();

        if ($spotIds === []) {
            return [];
        }

        return Spot::query()
            ->whereIn('id', $spotIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get()
            ->map(fn (Spot $spot) => $this->spotToCandidate($spot, $day))
            ->all();
    }

    private function spotToCandidate(Spot $spot, CarbonImmutable $day): Candidate
    {
        $category = $spot->category instanceof \BackedEnum
            ? $spot->category->value
            : (string) $spot->category;

        [$opensAt, $closesAt, $closedToday] = $this->hoursOn($spot->opening_hours, $day);
        $tags = is_array($spot->tags) ? $spot->tags : [];

        // No real hours → typical hours for the category, marked assumed. This
        // is what keeps museums out of 22:00 plans and playgrounds out of the
        // dark; verified opening_hours always win over the defaults.
        $hoursAssumed = false;
        if ($opensAt === null && $closesAt === null && ! $closedToday) {
            [$opensAt, $closesAt] = CategoryHours::defaults($category, $day);
            $hoursAssumed = $opensAt !== null || $closesAt !== null;
        }

        return new Candidate(
            id: "spot:{$spot->id}",
            type: 'spot',
            name: $spot->name,
            lat: (float) $spot->lat,
            lng: (float) $spot->lng,
            veedel: $spot->veedel ?? null,
            category: $category,
            outdoor: in_array($category, self::OUTDOOR_CATEGORIES, true),
            typicalDurationMin: self::DEFAULT_DURATION_MIN[$category] ?? self::DEFAULT_DURATION_MIN['default'],
            costTier: $this->spotCostTier($spot),
            opensAt: $opensAt,
            closesAt: $closesAt,
            isLandmark: isset($tags['wikidata']) || isset($tags['wikipedia']),
            closedToday: $closedToday,
            hoursAssumed: $hoursAssumed,
        );
    }

    /**
     * Resolve a spot's structured opening_hours into concrete open/close times
     * on the plan's day. Unknown/empty hours → assume open; a weekday mapped to
     * no intervals → closed.
     *
     * Accepts `mixed`, not `?array`: scraped spots (restaurants) can store a raw
     * OSM string ("Mo-Fr 09:00-18:00") instead of the importer's structured
     * array, and a hard `?array` hint would fatal the whole plan on the first
     * such spot. Anything that isn't the structured array is treated as unknown
     * hours (assume open) by the guard below.
     *
     * @param  array<string, mixed>|string|null  $hours
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable, 2: bool} [opensAt, closesAt, closedToday]
     */
    private function hoursOn(mixed $hours, CarbonImmutable $day): array
    {
        if (! is_array($hours) || $hours === []) {
            return [null, null, false];
        }

        $weekday = strtolower($day->format('D'));
        if (! array_key_exists($weekday, $hours)) {
            return [null, null, false];
        }

        $intervals = $hours[$weekday];
        if (! is_array($intervals) || $intervals === []) {
            return [null, null, true];
        }

        $first = $intervals[0];
        $last = $intervals[count($intervals) - 1];
        if (! is_array($first) || ! is_array($last)) {
            return [null, null, false];
        }

        $opensAt = $this->timeOn($day, (string) $first[0]);
        $closesAt = $this->timeOn($day, (string) $last[1]);
        if ($closesAt->lessThanOrEqualTo($opensAt)) {
            $closesAt = $closesAt->addDay(); // crosses midnight
        }

        return [$opensAt, $closesAt, false];
    }

    private function timeOn(CarbonImmutable $day, string $hhmm): CarbonImmutable
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hhmm)), 2, 0);

        return $h >= 24 ? $day->startOfDay()->addDay() : $day->setTime($h, $m);
    }

    /**
     * @return list<Candidate>
     */
    /** Event categories/chips that mean an open-air thing rain should discourage. */
    private const OUTDOOR_EVENT_HINTS = ['market', 'festival', 'open_air', 'street', 'flohmarkt', 'food', 'sport', 'outdoor', 'park'];

    private function eventCandidates(Constraints $constraints): array
    {
        return Event::query()
            // Only quality-gated events reach a plan — the same filter the events
            // page uses. Without it, unvetted scraped rows landed in itineraries.
            ->visible()
            ->with('venue')
            ->whereBetween('starts_at', [$constraints->windowStart, $constraints->windowEnd])
            ->orderBy('starts_at')
            ->limit(50)
            ->get()
            ->filter(fn (Event $event) => $event->lat !== null && $event->lng !== null)
            ->map(fn (Event $event) => new Candidate(
                id: "event:{$event->id}",
                type: 'event',
                name: $event->title,
                lat: (float) $event->lat,
                lng: (float) $event->lng,
                veedel: $event->venue?->veedel,
                category: (string) ($event->category ?? 'event'),
                outdoor: $this->eventIsOutdoor($event),
                typicalDurationMin: $event->ends_at
                    ? max(30, (int) $event->starts_at->diffInMinutes($event->ends_at))
                    : 120,
                costTier: $event->is_free ? 'free' : 'normal',
                opensAt: null,
                closesAt: null,
                fixedStart: CarbonImmutable::parse($event->starts_at),
                swappable: false, // fixed-time, like appointments
                // A curated (expat-relevant / high-quality) event is the kind of
                // thing worth building a day around — let it win the hero role.
                isLandmark: (bool) $event->is_curated,
            ))
            ->values()
            ->all();
    }

    /** True when an event's category or chips read as open-air (rain should discourage it). */
    private function eventIsOutdoor(Event $event): bool
    {
        $haystack = mb_strtolower((string) ($event->category ?? '').' '.implode(' ', is_array($event->chips) ? $event->chips : []));

        foreach (self::OUTDOOR_EVENT_HINTS as $hint) {
            if (str_contains($haystack, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function spotCostTier(Spot $spot): string
    {
        $category = $spot->category instanceof \BackedEnum ? $spot->category->value : (string) $spot->category;

        if (in_array($category, self::OUTDOOR_CATEGORIES, true) || $category === 'library') {
            return 'free';
        }

        return match ($spot->price_range ?? null) {
            '€' => 'low',
            default => 'normal',
        };
    }
}
