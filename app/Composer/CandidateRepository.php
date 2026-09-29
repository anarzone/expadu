<?php

namespace App\Composer;

use App\Enums\SpotCategory;
use App\Exceptions\CologneBoundaryUnavailable;
use App\Models\Event;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use App\Services\CologneServiceArea;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;

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

    public function __construct(
        private readonly CologneServiceArea $serviceArea,
    ) {}

    /**
     * @return list<Candidate>
     */
    public function candidatesFor(Constraints $constraints, float $originLat = self::COLOGNE_LAT, float $originLng = self::COLOGNE_LNG): array
    {
        $events = $this->eventCandidates($constraints, $originLat, $originLng);
        $spots = $this->spotCandidates($constraints, $originLat, $originLng);

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
    private function spotCandidates(Constraints $constraints, float $originLat, float $originLng): array
    {
        $grouping = app(DestinationGrouping::class);
        $requested = array_values(array_unique(array_merge([], ...array_map(SpotCategory::finesForSelector(...), $constraints->categories))));
        $includeFacilities = $constraints->activities !== [];
        $query = $grouping->eligible(Spot::query(), $includeFacilities)
            ->when(! $includeFacilities, fn ($query) => $query->where(fn ($where) => $where->whereNull('destination_spot_id')->orWhereIn('category', $requested)))
            ->when($requested !== [], fn ($query) => $query->whereIn('category', $requested));
        $selected = [];
        $filter = app(FeasibilityFilter::class);

        // Hints use the minimum distance over every source/review point. Once
        // that lower bound exceeds a full category's farthest accepted result,
        // no remaining row in that category can displace it. Every admitted row
        // still passes the shared current-fact and hard-constraint policies.
        $hints = app(PlaceSearchHints::class)->ordered(clone $query, $constraints, $originLat, $originLng);
        $offset = 0;
        while ($offset < count($hints)) {
            $ids = [];
            while ($offset < count($hints) && count($ids) < 64) {
                $hint = $hints[$offset++];
                $pool = $selected[$hint['category']] ?? [];
                if (count($pool) >= self::PER_CATEGORY && $hint['minimum_km'] > $pool[array_key_last($pool)]->distanceKmFromOrigin) {
                    continue;
                }
                $ids[] = $hint['id'];
            }
            if ($ids === []) {
                break;
            }
            $spots = (clone $query)->whereIn('spots.id', $ids)->get();
            $facts = app(PlaceFacts::class)->resolveMany($spots);
            $groups = $grouping->groupIds($spots->modelKeys(), $includeFacilities);
            foreach ($spots as $spot) {
                $resolved = $facts[$spot->id];
                if ($resolved['location']['map_point']['status'] !== 'known') {
                    continue;
                }
                $candidate = $this->spotToCandidate($spot, $constraints->windowStart, $resolved, $originLat, $originLng, $groups[$spot->id]);
                if (! $filter->matchesDiscovery($constraints, $candidate)) {
                    continue;
                }
                $selected[$candidate->category][] = $candidate;
                usort($selected[$candidate->category], fn (Candidate $a, Candidate $b): int => [$a->distanceKmFromOrigin, (int) substr($a->id, 5)] <=> [$b->distanceKmFromOrigin, (int) substr($b->id, 5)]);
                $selected[$candidate->category] = array_slice($selected[$candidate->category], 0, self::PER_CATEGORY);
            }
        }
        $candidates = array_merge([], ...array_values($selected));
        usort($candidates, fn (Candidate $a, Candidate $b): int => [$a->distanceKmFromOrigin, (int) substr($a->id, 5)] <=> [$b->distanceKmFromOrigin, (int) substr($b->id, 5)]);

        return $candidates;
    }

    /**
     * Load specific spots by candidate id ("spot:12") regardless of rating —
     * so a "plan around this" pin from the home feed is guaranteed to be in
     * the pool even if it falls outside the top-rated cap.
     *
     * @param  list<string>  $ids
     * @return list<Candidate>
     */
    public function byIds(array $ids, CarbonImmutable $day, float $originLat = self::COLOGNE_LAT, float $originLng = self::COLOGNE_LNG): array
    {
        $ids = app(PlaceIdentity::class)->candidateIds($ids);
        $spotIds = collect($ids)
            ->filter(fn ($id) => is_string($id) && str_starts_with($id, 'spot:'))
            ->map(fn ($id) => (int) substr($id, 5))
            ->filter()
            ->all();

        if ($spotIds === []) {
            return [];
        }

        $spots = app(DestinationGrouping::class)->eligible(Spot::query(), includeActivityFacilities: true)
            ->whereIn('id', $spotIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->get();
        $groups = app(DestinationGrouping::class)->groupIds($spots->modelKeys(), includeActivityFacilities: true);
        $facts = app(PlaceFacts::class)->resolveMany($spots);

        return $spots
            ->map(fn (Spot $spot) => $this->spotToCandidate($spot, $day, $facts[$spot->id], $originLat, $originLng, $groups[$spot->id]))
            ->all();
    }

    /** @param array<string, mixed> $facts */
    private function spotToCandidate(Spot $spot, CarbonImmutable $day, array $facts, float $originLat = self::COLOGNE_LAT, float $originLng = self::COLOGNE_LNG, ?int $destinationId = null): Candidate
    {
        $category = $spot->category instanceof \BackedEnum
            ? $spot->category->value
            : (string) $spot->category;

        [$opensAt, $closesAt, $closedToday] = $this->hoursOn($facts['hours']['parsed'], $day);
        $tags = is_array($spot->tags) ? $spot->tags : [];
        $mapPoint = $facts['location']['map_point'];
        $entrance = $facts['location']['entrance_point'];
        $routePoint = $entrance['status'] === 'verified' ? $entrance : $mapPoint;
        $lat = $routePoint['lat'] !== null ? (float) $routePoint['lat'] : (float) $spot->lat;
        $lng = $routePoint['lng'] !== null ? (float) $routePoint['lng'] : (float) $spot->lng;

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
            destinationGroupId: 'spot:'.($destinationId ?? $spot->id),
            type: 'spot',
            name: $facts['name']['value'] ?? $spot->name,
            lat: $lat,
            lng: $lng,
            veedel: $spot->veedel ?? null,
            category: $category,
            outdoor: in_array($category, self::OUTDOOR_CATEGORIES, true),
            typicalDurationMin: self::DEFAULT_DURATION_MIN[$category] ?? self::DEFAULT_DURATION_MIN['default'],
            costTier: $this->spotCostTier($spot, $facts['fee']),
            opensAt: $opensAt,
            closesAt: $closesAt,
            isLandmark: isset($tags['wikidata']) || isset($tags['wikipedia']),
            closedToday: $closedToday,
            hoursAssumed: $hoursAssumed,
            description: $facts['description']['value'],
            tags: $this->textTags([
                ...$tags,
                ...collect($facts['negative_facts'])->mapWithKeys(fn (mixed $value, string $key): array => ["negative:{$key}" => $value])->all(),
                'resolved_access' => $facts['access']['value'],
                'resolved_fee' => $facts['fee']['value'],
            ]),
            qualityScore: $spot->rating !== null ? min(1.0, max(0.0, (float) $spot->rating / 5.0)) : null,
            travelMinutesFromOrigin: (new TravelEstimator)->minutesBetween($originLat, $originLng, $lat, $lng),
            access: $facts['access']['value'],
            factConflicts: $facts['conflicts'],
            factRevision: $facts['revision'],
            placeFacts: $facts,
            distanceKmFromOrigin: NearbyPlaces::km($originLat, $originLng, $lat, $lng),
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

    private function eventCandidates(Constraints $constraints, float $originLat, float $originLng): array
    {
        if ($constraints->activities !== []) {
            return [];
        }

        $occurrences = Event::occurringBetween($constraints->windowStart, $constraints->windowEnd);
        try {
            $coordinates = $this->serviceArea->eventCoordinates(
                $occurrences->pluck('event.id')->map(fn ($id) => (int) $id)->unique()->values()->all(),
            );
        } catch (CologneBoundaryUnavailable) {
            return [];
        }

        return $occurrences
            ->reject(fn (array $occurrence): bool => in_array('multi-day-uncertain', $occurrence['event']->tags ?? [], true))
            ->filter(fn (array $occurrence) => $coordinates->has($occurrence['event']->id))
            ->sortBy(fn (array $occurrence) => NearbyPlaces::km(
                $originLat,
                $originLng,
                (float) $coordinates->get($occurrence['event']->id)->lat,
                (float) $coordinates->get($occurrence['event']->id)->lng,
            ))
            ->map(function (array $occurrence) use ($coordinates, $originLat, $originLng): Candidate {
                /** @var Event $event */
                $event = $occurrence['event'];
                $point = $coordinates->get($event->id);
                $candidateId = $event->recurrence !== null
                    ? "event:{$event->id}:{$occurrence['starts_at']->getTimestamp()}"
                    : "event:{$event->id}";

                return new Candidate(
                    id: $candidateId,
                    type: 'event',
                    name: $event->title_en ?: $event->title,
                    lat: (float) $point->lat,
                    lng: (float) $point->lng,
                    veedel: $event->venue?->veedel,
                    category: (string) ($event->category ?? 'event'),
                    outdoor: $this->eventIsOutdoor($event),
                    typicalDurationMin: $occurrence['ends_at']
                        ? max(30, (int) $occurrence['starts_at']->diffInMinutes($occurrence['ends_at']))
                        : 120,
                    costTier: $event->is_free ? 'free' : 'normal',
                    opensAt: null,
                    closesAt: null,
                    fixedStart: $occurrence['starts_at'],
                    swappable: false, // a selected fixed-time occurrence cannot be freely rescheduled
                    // A curated (expat-relevant / high-quality) event is the kind of
                    // thing worth building a day around — let it win the hero role.
                    isLandmark: (bool) $event->is_curated,
                    description: $event->summary_en ?: $event->description_en ?: $event->description,
                    tags: $this->textTags([...(array) $event->tags, ...(array) $event->chips]),
                    qualityScore: $event->quality_score,
                    travelMinutesFromOrigin: (new TravelEstimator)->minutesBetween($originLat, $originLng, (float) $point->lat, (float) $point->lng),
                    distanceKmFromOrigin: NearbyPlaces::km($originLat, $originLng, (float) $point->lat, (float) $point->lng),
                );
            })
            ->filter(fn (Candidate $candidate): bool => app(FeasibilityFilter::class)->matchesDiscovery($constraints, $candidate))
            ->take(50)
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

    /** @param array<string, mixed> $fee */
    private function spotCostTier(Spot $spot, array $fee): string
    {
        if (in_array($fee['status'], ['conflicting', 'conditional'], true)) {
            return 'unknown';
        }
        if ($fee['value'] === 'free') {
            return 'free';
        }
        if ($fee['value'] === 'paid') {
            return ($spot->price_range ?? null) === '€' ? 'low' : 'normal';
        }

        return match ($spot->price_range ?? null) {
            '€' => 'low',
            '€€', '€€€' => 'normal',
            default => 'unknown',
        };
    }

    /** @return list<string> */
    private function textTags(array $tags): array
    {
        $values = array_is_list($tags) ? $tags : [...array_keys($tags), ...array_values($tags)];

        return collect($values)
            ->filter(fn (mixed $tag): bool => is_scalar($tag))
            ->map(fn (mixed $tag): string => (string) $tag)
            ->unique()
            ->values()
            ->all();
    }
}
