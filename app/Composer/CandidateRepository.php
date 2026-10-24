<?php

namespace App\Composer;

use App\Enums\SpotCategory;
use App\Exceptions\CologneBoundaryUnavailable;
use App\Models\Event;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use App\Places\SourceTagFacts;
use App\Services\CologneServiceArea;
use App\Services\NearbyPlaces;
use App\Services\OpeningHoursParser;
use App\Support\EventEvidence;
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

    /** How far the assistant's nearest places go (nearestSpots), in km. */
    private const NEAR_KM = 5.0;

    /** Cologne centre — the origin a plan falls back to when the user has no location. */
    public const COLOGNE_LAT = 50.9375;

    public const COLOGNE_LNG = 6.9603;

    // As SpotCategory::isOutdoor() has them (a zoo is outdoors: the assistant called one "indoor", 22 Oct 2026).
    private const OUTDOOR_CATEGORIES = ['park', 'playground', 'pitch', 'basketball', 'tennis', 'table_tennis', 'boules', 'lake', 'nature', 'dog_park', 'bbq', 'picnic', 'viewpoint', 'skatepark', 'market', 'campsite', 'zoo'];

    private const GENERIC_MICRO_FACILITY_LABELS = [
        'Spielplatz', 'Bolzplatz', 'Basketballplatz', 'Tennisplatz',
        'Tischtennisplatte', 'Boulebahn', 'Skatepark', 'Hundewiese',
        'Grillplatz', 'Picknickplatz',
    ];

    private const DEFAULT_DURATION_MIN = [
        'park' => 75,
        'cafe' => 60,
        'library' => 90,
        'coworking' => 120,
        'restaurant' => 75,
        'bar' => 90,
        'default' => 60,
    ];

    /** A stop looked at on the way: a fountain, a monument, an artwork. */
    private const SHORT_STOP_MIN = 15;

    /** Coarse kinds a day out leaves out unless they're asked for: they're named when wanted. */
    private const NOT_A_DAY_OUT = ['shopping', 'services', 'health', 'stay'];

    /** Places a day out includes only with the kids or for a sport asked for. */
    private const FOR_KIDS_OR_SPORT = ['playground', 'pitch', 'basketball', 'tennis', 'table_tennis', 'boules', 'skatepark', 'dog_park', 'bbq'];

    /**
     * What a day out holds when they name no kind (owner, 24 Oct 2026): not a library or reading room
     * (a place to sit and study) unless the day is a quiet one; not a playground, pitch or court unless
     * it's with the kids or for a sport; not shops, services, health or stays.
     *
     * @param  list<Candidate>  $candidates
     * @return list<Candidate>
     */
    public static function dayOut(Constraints $constraints, array $candidates): array
    {
        if ($constraints->categories !== [] || $constraints->sequence !== []) {
            return $candidates;
        }
        $out = [...array_merge(...array_map(SpotCategory::finesForCoarse(...), self::NOT_A_DAY_OUT)),
            ...(in_array('quiet', $constraints->moods, true) ? [] : [SpotCategory::Library->value]),
            ...($constraints->activities !== [] || $constraints->companions === 'kids' ? [] : self::FOR_KIDS_OR_SPORT)];

        return array_values(array_filter($candidates, fn (Candidate $c): bool => $c->type !== 'spot' || ! in_array($c->category, $out, true)));
    }

    public function __construct(
        private readonly CologneServiceArea $serviceArea,
    ) {}

    /** @param array<string, mixed> $tags */
    private static function shortStop(array $tags): bool
    {
        return ($tags['amenity'] ?? null) === 'fountain' || in_array($tags['historic'] ?? null, ['monument', 'memorial'], true) || ($tags['tourism'] ?? null) === 'artwork';
    }

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
     * The places nearest a point that fit, at most `$perCategory` of each kind, nearest first,
     * stopping once `$enough` are found and no place left can be nearer: what Composer's
     * assistant suggests from (its search_places), without the planner's whole pool of a
     * dozen a kind plus the listings, which it doesn't use (owner, 22 Oct 2026: every reply
     * under 6 s).
     *
     * @return list<Candidate>
     */
    public function nearestSpots(Constraints $constraints, float $originLat, float $originLng, int $perCategory = 2, int $enough = 24): array
    {
        return $this->spotCandidates($constraints, $originLat, $originLng, $perCategory, $enough);
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
    private function spotCandidates(Constraints $constraints, float $originLat, float $originLng, int $perCategory = self::PER_CATEGORY, ?int $enough = null): array
    {
        // The fact rules make these queries look costly to PostgreSQL, so it compiles them
        // first (JIT): 3–4 s for 30 ms of work. Off for these queries only, same results
        // (owner, 22 Oct 2026: every Composer reply under 6 s).
        return DB::transaction(function () use ($constraints, $originLat, $originLng, $perCategory, $enough): array {
            DB::statement('SET LOCAL jit = off');

            return $this->nearestFitting($constraints, $originLat, $originLng, $perCategory, $enough);
        });
    }

    /** @return list<Candidate> */
    private function nearestFitting(Constraints $constraints, float $originLat, float $originLng, int $perCategory, ?int $enough): array
    {
        $grouping = app(DestinationGrouping::class);
        $requested = array_values(array_unique(array_merge([], ...array_map(SpotCategory::finesForSelector(...), $constraints->categories))));
        $requestedFacilities = array_values(array_filter(
            $constraints->categories,
            fn (string $category): bool => SpotCategory::tryFrom($category)?->isActivityFacility() ?? false,
        ));
        $includeFacilities = $constraints->activities !== [] || $requestedFacilities !== [];
        $query = $grouping->eligible(Spot::query(), $includeFacilities)
            ->when($constraints->activities === [] && $requestedFacilities !== [], fn ($query) => $query->where(fn ($where) => $where->where('spots.is_recommendable', true)->orWhereIn('spots.category', $requestedFacilities)))
            ->when(! $includeFacilities, fn ($query) => $query->where(fn ($where) => $where->whereNull('destination_spot_id')->orWhereIn('category', $requested)))
            ->when($requested !== [], fn ($query) => $query->whereIn('category', $requested))
            // A pharmacy or supermarket is an errand, not an afternoon out:
            // only a request that names one brings it into the plan.
            ->when($requested === [], fn ($query) => $query->whereNotIn('category', SpotCategory::errands()));
        $labels = implode('|', array_map(fn (string $label): string => preg_quote($label, '/'), self::GENERIC_MICRO_FACILITY_LABELS));
        $query->where(function ($where) use ($labels, $includeFacilities): void {
            $where->whereRaw('spots.name !~* ?', ['^('.$labels.')(?:\\s*·.*)?$']);
            if ($includeFacilities) {
                $where->orWhere(fn ($qualified) => app(PlaceFacts::class)->activityQualified($qualified));
            }
        });
        $selected = [];
        $filter = app(FeasibilityFilter::class);

        // Hints use the minimum distance over every source/review point. Once
        // that lower bound exceeds a full category's farthest accepted result,
        // no remaining row in that category can displace it. Every admitted row
        // still passes the shared current-fact and hard-constraint policies.
        $hints = app(PlaceSearchHints::class)->ordered(clone $query, $constraints, $originLat, $originLng);
        $offset = 0;
        while ($offset < count($hints)) {
            // For the assistant, within a few kilometres: past them, few that fit (step-free, a sport) aren't worth the wait.
            if ($enough !== null && $hints[$offset]['minimum_km'] > self::NEAR_KM) {
                break;
            }
            // Enough already, and every place left is farther than the last of them.
            if ($enough !== null && $offset > 0) {
                $distances = array_map(fn (Candidate $c): float => (float) $c->distanceKmFromOrigin, array_merge([], ...array_values($selected)));
                sort($distances);
                if (count($distances) >= $enough && $hints[$offset]['minimum_km'] > $distances[$enough - 1]) {
                    break;
                }
            }
            $ids = [];
            while ($offset < count($hints) && count($ids) < 64) {
                $hint = $hints[$offset++];
                $pool = $selected[$hint['category']] ?? [];
                if (count($pool) >= $perCategory && $hint['minimum_km'] > $pool[array_key_last($pool)]->distanceKmFromOrigin) {
                    continue;
                }
                $ids[] = $hint['id'];
            }
            if ($ids === []) {
                break;
            }
            $spots = (clone $query)->whereIn('spots.id', $ids)->with('englishEnrichment')->get();
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
                $selected[$candidate->category] = array_slice($selected[$candidate->category], 0, $perCategory);
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

        $eventIds = array_values(array_filter($ids, fn ($id) => is_string($id) && str_starts_with($id, 'event:')));
        $events = $eventIds === [] ? [] : $this->eventCandidates(
            new Constraints($day->startOfDay(), $day->endOfDay()), $originLat, $originLng, $eventIds,
        );
        if ($spotIds === []) {
            return $events;
        }

        $spots = app(DestinationGrouping::class)->eligible(Spot::query(), includeActivityFacilities: true)
            ->whereIn('id', $spotIds)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->with('englishEnrichment')
            ->get();
        $groups = app(DestinationGrouping::class)->groupIds($spots->modelKeys(), includeActivityFacilities: true);
        $facts = app(PlaceFacts::class)->resolveMany($spots);

        $candidates = $spots
            ->filter(fn (Spot $spot): bool => $facts[$spot->id]['location']['map_point']['status'] === 'known')
            ->map(fn (Spot $spot) => $this->spotToCandidate($spot, $day, $facts[$spot->id], $originLat, $originLng, $groups[$spot->id]))
            ->values()->all();

        return [...$candidates, ...$events];
    }

    /** @param array<string, mixed> $facts */
    private function spotToCandidate(Spot $spot, CarbonImmutable $day, array $facts, float $originLat = self::COLOGNE_LAT, float $originLng = self::COLOGNE_LNG, ?int $destinationId = null): Candidate
    {
        $category = $spot->category instanceof \BackedEnum
            ? $spot->category->value
            : (string) $spot->category;

        [$opensAt, $closesAt, $closedToday] = $this->hoursOn($facts['hours']['parsed'], $day);
        // One of its closing dates ("Dec 24 off", a summer break): closed that day, whatever the week says.
        if (OpeningHoursParser::closedOn($facts['hours']['raw'] ?? null, $day)) {
            [$opensAt, $closesAt, $closedToday] = [null, null, true];
        }
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
            name: $facts['display_name'] ?? $facts['name']['value'] ?? $spot->name,
            lat: $lat,
            lng: $lng,
            veedel: $spot->veedel ?? null,
            category: $category,
            // A beer garden is outdoors whatever its category: rain counts against it.
            outdoor: in_array($category, self::OUTDOOR_CATEGORIES, true) || ($tags['amenity'] ?? null) === 'biergarten',
            // A fountain, monument or artwork is a short stop on the way, never an hour (owner, 24 Oct 2026: the Domfontäne for an hour).
            typicalDurationMin: self::shortStop($tags) ? self::SHORT_STOP_MIN : (self::DEFAULT_DURATION_MIN[$category] ?? self::DEFAULT_DURATION_MIN['default']),
            costTier: $this->spotCostTier($spot, $facts['fee']),
            opensAt: $opensAt,
            closesAt: $closesAt,
            isLandmark: isset($tags['wikidata']) || isset($tags['wikipedia']),
            closedToday: $closedToday,
            hoursAssumed: $hoursAssumed,
            description: $spot->englishEnrichment?->summary,
            vibeTags: $spot->englishEnrichment?->vibeTagNames() ?? [],
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
            sourceFacts: $this->sourceFacts($facts, $tags),
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

    private function eventCandidates(Constraints $constraints, float $originLat, float $originLng, ?array $ids = null): array
    {
        $occurrences = Event::occurringBetween($constraints->windowStart, $constraints->windowEnd);
        // Exact selection is independent of the discovery pool's nearest-50 cap.
        if ($ids !== null) {
            $occurrences = $occurrences->filter(function (array $occurrence) use ($ids): bool {
                $event = $occurrence['event'];
                $id = $event->recurrence !== null
                    ? "event:{$event->id}:{$occurrence['starts_at']->getTimestamp()}"
                    : "event:{$event->id}";

                return in_array($id, $ids, true);
            });
        }

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
            ->when($ids === null, fn ($items) => $items->take(50))
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
                    // No listed end: the plan still needs a length to schedule around.
                    typicalDurationMin: $occurrence['ends_at']
                        ? max(1, (int) $occurrence['starts_at']->diffInMinutes($occurrence['ends_at']))
                        : 120,
                    endListed: $occurrence['ends_at'] !== null,
                    costTier: $event->is_free ? 'free' : 'normal',
                    opensAt: null,
                    closesAt: null,
                    fixedStart: $occurrence['starts_at'],
                    swappable: false, // a selected fixed-time occurrence cannot be freely rescheduled
                    // A curated (expat-relevant / high-quality) event is the kind of
                    // thing worth building a day around — let it win the hero role.
                    isLandmark: (bool) $event->is_curated,
                    // The model's summary only when every figure in it is in the listing.
                    description: $event->source === 'manual'
                        ? ($event->summary_en ?: $event->description_en ?: $event->description)
                        : (EventEvidence::text($event->summary_en, EventEvidence::source($event))
                            ?? EventEvidence::text($event->description_en, EventEvidence::source($event))
                            ?? $event->description),
                    tags: $this->textTags([...(array) $event->tags, ...(array) $event->chips]),
                    qualityScore: $event->quality_score,
                    travelMinutesFromOrigin: (new TravelEstimator)->minutesBetween($originLat, $originLng, (float) $point->lat, (float) $point->lng),
                    distanceKmFromOrigin: NearbyPlaces::km($originLat, $originLng, (float) $point->lat, (float) $point->lng),
                );
            })
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

    /** Preserve source restrictions while publishing reviewed values and uncertainty. */
    private function sourceFacts(array $facts, array $tags): array
    {
        $effective = [];
        foreach ($facts['practical'] ?? [] as $key => $fact) {
            $effective[$key] = $fact['value'] ?? null;
        }
        $source = new SourceTagFacts($effective);
        $legacy = new SourceTagFacts($tags);

        return [
            'fee_status' => $facts['fee']['status'] === 'known' ? $facts['fee']['value'] : $facts['fee']['status'],
            'access_status' => $facts['access']['status'] === 'known' ? $facts['access']['value'] : $facts['access']['status'],
            'requires_arrangement' => $source->requiresArrangement() || $legacy->requiresArrangement(),
            'reservation' => $effective['reservation'] ?? null,
            'membership' => $tags['membership'] ?? null,
            'sport' => $effective['sport'] ?? null,
            'surface' => $effective['surface'] ?? null,
            'lit' => $effective['lit'] ?? null,
            'opening_hours' => $facts['hours']['raw'] ?? null,
            'availability' => 'unknown',
        ];
    }

    private function spotCostTier(Spot $spot, array $fee): string
    {
        if (in_array($fee['status'], ['conflicting', 'conditional'], true)) {
            return 'unknown';
        }
        if ($fee['value'] === 'free') {
            return in_array($spot->price_range, ['€', '€€', '€€€'], true) ? 'unknown' : 'free';
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
            ->uniqueStrict()
            ->values()
            ->all();
    }
}
