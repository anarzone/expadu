<?php

namespace App\Composer;

use App\Models\Event;
use App\Models\Spot;
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

    private const OUTDOOR_CATEGORIES = ['park', 'playground', 'pitch', 'basketball', 'lake', 'dog_park', 'bbq', 'viewpoint', 'skatepark'];

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
    public function candidatesFor(Constraints $constraints): array
    {
        return array_slice(
            [...$this->spotCandidates($constraints->windowStart), ...$this->eventCandidates($constraints)],
            0,
            self::MAX_CANDIDATES,
        );
    }

    /**
     * @return list<Candidate>
     */
    private function spotCandidates(CarbonImmutable $day): array
    {
        // A diverse, bounded pool: top ~12 per category. Ordering by rating
        // alone would fill the pool with cafés (only commercial venues are
        // rated); a flat cap would fill it with playgrounds. Per-category
        // ensures indoor culture competes with outdoor activities.
        $ids = DB::table(DB::raw('(SELECT id, ROW_NUMBER() OVER (PARTITION BY category ORDER BY rating DESC NULLS LAST, id) AS rn FROM spots WHERE lat IS NOT NULL AND lng IS NOT NULL) AS ranked'))
            ->where('rn', '<=', 12)
            ->pluck('id')
            ->all();

        return Spot::query()
            ->whereIn('id', $ids)
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
    private function eventCandidates(Constraints $constraints): array
    {
        return Event::query()
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
                veedel: null,
                category: (string) ($event->category ?? 'event'),
                outdoor: false,
                typicalDurationMin: $event->ends_at
                    ? max(30, (int) $event->starts_at->diffInMinutes($event->ends_at))
                    : 120,
                costTier: $event->is_free ? 'free' : 'normal',
                opensAt: null,
                closesAt: null,
                fixedStart: CarbonImmutable::parse($event->starts_at),
                swappable: false, // fixed-time, like appointments
            ))
            ->values()
            ->all();
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
