<?php

namespace App\Home;

use App\Composer\CategoryAppeal;
use App\Enums\EventCategory;
use App\Enums\SpotFeedbackState;
use App\Models\Event;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\UserTask;
use App\Profile\CategoryAffinity;
use App\Profile\Profile;
use App\Services\NearbyPlaces;
use App\Support\EventOccurrencePresenter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The browse half of the home screen: Netflix-style discovery rails, ranked by
 * deterministic signals (no ML, no LLM) in priority order — the user's
 * SITUATION (CategoryAffinity) leads, learned IntentWeights refine, category
 * appeal demotes cafés, then home-area + weather. Each rail carries a personal,
 * contextual title (RailCopy — e.g. "Stay dry this Monday"); some rails are
 * themselves situation-specific (with the kids; get oriented).
 */
class DiscoveryFeed
{
    private const OUTDOOR = ['park', 'playground', 'pitch', 'basketball', 'lake', 'dog_park', 'bbq', 'viewpoint', 'skatepark', 'tennis', 'table_tennis', 'boules', 'swimming'];

    /** Pull the whole light catalogue so every category can compete. */
    private const POOL = 2000;

    /** The catalogue scan is global; cache it briefly so it isn't re-run per user. */
    private const SCAN_CACHE_KEY = 'discovery:spot-scan';

    private const SCAN_TTL = 300;

    /** Cap on sit-down/work venues per rail, so a rail isn't all cafés. */
    private const LOW_APPEAL_CAP = 2;

    /** Cap per category per rail, so a rail isn't 12 identical playgrounds. */
    private const CAP_PER_CATEGORY = 2;

    /**
     * "Your home area" must read as genuinely nearby. We derive homeAreas from
     * the whole administrative Bezirk (≈12 Stadtteile, ~14km across in
     * Chorweiler), so membership alone let spots 10km+ away wear an "in your
     * area" badge. Bound the home rail to a neighbourhood radius of the origin
     * (≈30 min on foot at the edge, most cards far closer since it's
     * nearest-first). Staging: 103 home-area spots fall inside this radius of
     * Merkenich, so the rail still fills comfortably.
     */
    private const HOME_RADIUS_KM = 2.5;

    public function __construct(
        private readonly NearbyPlaces $nearby,
        private readonly EventOccurrencePresenter $eventPresenter,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(HomeContext $context): array
    {
        $profile = $context->profile;
        $weights = $context->intentWeights;
        $rain = $context->rainExpected;
        $homeAreas = $profile->defaultAreas;
        $affinity = CategoryAffinity::map($profile);
        $originLat = $context->originLat;
        $originLng = $context->originLng;

        // Drop places the user took out of discovery — "been" (consumed) or
        // "not interested" (rejected). Per-user, so it sits outside the cached
        // scan below.
        $hidden = $this->hiddenSpotIds($context->userId);

        // The pool is the NEAREST spots to where the user actually is (cached
        // per ~1km cell), so "nearby" is genuinely nearby — not 2,000 arbitrary
        // lowest-id rows from across the city. Scoring stays per-request and now
        // rewards proximity, so a park next door beats a far museum of equal
        // appeal. With no known origin it falls back to the global catalogue.
        $scored = $this->spotPool($originLat, $originLng)
            ->reject(fn (Spot $spot) => isset($hidden[$spot->id]))
            ->map(fn (Spot $spot) => $this->scoreSpot($spot, $weights, $homeAreas, $rain, $affinity, $originLat, $originLng))
            ->sortByDesc('score')
            ->values();

        // Cross-rail de-dup: a spot shown in an earlier (higher-priority) rail
        // is skipped by later ones, so the leisure rails never repeat the same
        // venue. Built in priority order — made_for_today claims its picks
        // first; the situation/home/new rails then show their next-best
        // *distinct* picks (the 2,000-spot pool is deep enough to still fill).
        $shown = [];

        // Situation- and context-aware title for the lead rail (deterministic
        // template bank — no LLM): rain → weekend/evening → just-arrived → day.
        [$leadTitle, $leadReason] = RailCopy::leadRail(
            $context->now,
            $rain,
            $context->isWeekendWindow,
            $context->isEvening,
            CategoryAffinity::isNewArrival($profile),
        );

        $rails = array_values(array_filter([
            $scored->isNotEmpty()
                ? $this->rail(
                    'made_for_today',
                    $leadTitle,
                    $leadReason,
                    '/explore',
                    $this->pickCards($scored, 12, null, false, $rain, $shown),
                )
                : null,
            $this->situationRail($scored, $profile, $rain, $shown),
            $this->tonightRail($context),
            $this->homeRail($scored, $homeAreas, $profile->veedel, $rain, $shown, $originLat, $originLng),
            $this->newRail($scored, $homeAreas, $rain, $shown),
            $this->paperworkRail($context),
        ]));

        return $rails;
    }

    /**
     * Situation drives, behaviour refines, appeal is the café-demoting
     * baseline (rating is ~0% populated, so it's not a signal).
     *
     * @param  array<string, float>  $weights
     * @param  list<string>  $homeAreas
     * @param  array<string, float>  $affinity
     * @return array{spot: Spot, category: string, score: float}
     */
    private function scoreSpot(Spot $spot, array $weights, array $homeAreas, bool $rain, array $affinity, ?float $originLat, ?float $originLng): array
    {
        $category = $this->category($spot);
        $key = "{$category}|".($spot->veedel ?? '');

        $score = CategoryAffinity::score($category, $affinity) * 45.0   // situation drives
            + ($weights[$key] ?? 0.0) * 25.0                            // behaviour refines
            + CategoryAppeal::score($category) * 20.0                   // café-demoting baseline
            + (in_array($spot->veedel, $homeAreas, true) ? 8.0 : 0.0)   // home-area nudge
            + $this->proximityScore($spot, $originLat, $originLng)      // near ranks above far
            + ($rain && in_array($category, self::OUTDOOR, true) ? -25.0 : 0.0);

        return ['spot' => $spot, 'category' => $category, 'score' => $score];
    }

    /**
     * A distance-decay bonus so a place a short hop away outranks an equally
     * appealing one across the city — the term that makes "nearby" true. Full
     * value at the door, fading to zero by ~6km (a sensible browse radius in
     * Cologne). Off entirely when we don't know where the user is.
     */
    private function proximityScore(Spot $spot, ?float $originLat, ?float $originLng): float
    {
        if ($originLat === null || $originLng === null || $spot->lat === null || $spot->lng === null) {
            return 0.0;
        }

        $km = NearbyPlaces::km($originLat, $originLng, (float) $spot->lat, (float) $spot->lng);

        return 35.0 * max(0.0, 1.0 - $km / 6.0);
    }

    /**
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @param  array<int, true>  $shown  spot ids already placed in earlier rails
     */
    private function homeRail(Collection $scored, array $homeAreas, ?string $veedel, bool $rain, array &$shown, ?float $originLat, ?float $originLng): ?array
    {
        $home = $scored->filter(fn ($x) => in_array($x['spot']->veedel, $homeAreas, true));

        // Bound to a neighbourhood radius of the origin — the SAME anchor the
        // card "min away" is measured from — so an "in your area" badge and the
        // travel figure can never contradict each other, and re-order
        // nearest-first so the closest spots lead. With no known origin we can't
        // measure distance (and travel_min is null too, so no number shows) —
        // fall back to plain membership.
        if ($originLat !== null && $originLng !== null) {
            $home = $home
                ->map(function (array $x) use ($originLat, $originLng): array {
                    $x['km'] = ($x['spot']->lat === null || $x['spot']->lng === null)
                        ? INF
                        : NearbyPlaces::km($originLat, $originLng, (float) $x['spot']->lat, (float) $x['spot']->lng);

                    return $x;
                })
                ->filter(fn (array $x) => $x['km'] <= self::HOME_RADIUS_KM)
                ->sortBy('km')
                ->values();
        }

        if ($home->isEmpty()) {
            return null;
        }

        $cards = $this->pickCards($home, 12, '📍 in your area', false, $rain, $shown);
        if ($cards === []) {
            return null;
        }

        return $this->rail('around_home', 'Around '.($veedel ?? 'you'), 'your home area', '/explore', $cards);
    }

    /**
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @param  array<int, true>  $shown  spot ids already placed in earlier rails
     */
    private function newRail(Collection $scored, array $homeAreas, bool $rain, array &$shown): ?array
    {
        $new = $scored->filter(fn ($x) => $x['spot']->veedel && ! in_array($x['spot']->veedel, $homeAreas, true));
        if ($new->isEmpty()) {
            return null;
        }

        $cards = $this->pickCards($new, 12, 'new to you', true, $rain, $shown);
        if ($cards === []) {
            return null;
        }

        return $this->rail('try_new', 'Somewhere new for you', "a corner you haven't explored", '/explore', $cards);
    }

    /**
     * A situation-specific rail: family → the kids; just-arrived → orientation.
     *
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @param  array<int, true>  $shown  spot ids already placed in earlier rails
     */
    private function situationRail(Collection $scored, Profile $profile, bool $rain, array &$shown): ?array
    {
        if (CategoryAffinity::hasKids($profile)) {
            $kids = $scored->filter(fn ($x) => in_array($x['category'], CategoryAffinity::KID_FRIENDLY, true));
            $cards = $this->pickCards($kids, 12, null, false, $rain, $shown);
            if ($cards === []) {
                return null;
            }

            return $this->rail('with_kids', 'With the kids', 'family-friendly picks', '/explore', $cards);
        }

        if (CategoryAffinity::isNewArrival($profile)) {
            $landmarks = $scored->filter(fn ($x) => in_array($x['category'], CategoryAffinity::LANDMARK, true));
            $cards = $this->pickCards($landmarks, 12, '🧭 worth knowing', false, $rain, $shown);
            if ($cards === []) {
                return null;
            }

            return $this->rail('get_oriented', 'Get oriented in '.($profile->veedel ?? 'Cologne'), 'landmarks to know', '/explore', $cards);
        }

        return null;
    }

    /**
     * Pick cards for a rail, capping low-appeal (café/restaurant/bar/cowork)
     * so a rail never becomes a coffee list.
     *
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @param  array<int, true>  $shown  spot ids already placed in earlier rails; placed ids are added back
     * @return list<array<string, mixed>>
     */
    private function pickCards(Collection $scored, int $limit, ?string $cardReason, bool $markNew, bool $rain, array &$shown = []): array
    {
        $cards = [];
        $lowAppeal = 0;
        $perCategory = [];

        foreach ($scored as $x) {
            if (count($cards) >= $limit) {
                break;
            }
            $dedupKey = $this->dedupKey($x['spot']);
            // Cross-rail de-dup, keyed on what the user actually sees (name +
            // Veedel) — not the id. Many OSM spots share a generic name like
            // "Spielplatz", so two distinct rows would still READ as a repeat.
            if (isset($shown[$dedupKey])) {
                continue;
            }
            $category = $x['category'];
            // Variety: no more than CAP_PER_CATEGORY of any one category.
            if (($perCategory[$category] ?? 0) >= self::CAP_PER_CATEGORY) {
                continue;
            }
            if (CategoryAppeal::isLowAppeal($category)) {
                if ($lowAppeal >= self::LOW_APPEAL_CAP) {
                    continue;
                }
                $lowAppeal++;
            }
            $perCategory[$category] = ($perCategory[$category] ?? 0) + 1;
            $shown[$dedupKey] = true;

            $reason = $cardReason
                ?? ($rain && ! in_array($x['category'], self::OUTDOOR, true) ? 'dry pick' : null);

            $cards[] = [
                'id' => "spot:{$x['spot']->id}",
                'name' => $x['spot']->name,
                'veedel' => $x['spot']->veedel,
                'category' => $x['category'],
                'cost' => $x['spot']->price_range,
                'lat' => (float) $x['spot']->lat,
                'lng' => (float) $x['spot']->lng,
                'is_new' => $markNew,
                'kind' => 'spot',
                'href' => null,
                'reason' => $reason,
                'photo_url' => $x['spot']->photo_url,
                'photo_attribution' => $x['spot']->photo_attribution,
            ];
        }

        return $cards;
    }

    private function tonightRail(HomeContext $context): ?array
    {
        // Skip any event already surfaced as an urgent "Right now" tile.
        $events = $context->tonightEvents
            ->reject(fn (Event $e) => $context->isClaimed("event:{$e->id}"))
            ->filter(fn (Event $e) => $e->lat !== null && $e->lng !== null)
            ->take(12)
            ->values();

        if ($events->isEmpty()) {
            return null;
        }

        return $this->rail('tonight', 'Tonight in Cologne', 'in your window', '/events',
            $events->map(function (Event $e) {
                $photo = $this->eventPresenter->photo($e);

                return [
                    'id' => "event:{$e->id}",
                    'name' => $e->title,
                    'veedel' => $e->location_name,
                    'category' => EventCategory::fromLegacy($e->category)->value,
                    'cost' => $e->is_free ? 'free' : null,
                    'lat' => (float) $e->lat,
                    'lng' => (float) $e->lng,
                    'is_new' => false,
                    'kind' => 'event',
                    'href' => null,
                    'reason' => $e->starts_at->format('H:i'),
                    'photo_url' => $photo['url'],
                    'photo_attribution' => $photo['attribution'],
                ];
            })->all());
    }

    private function paperworkRail(HomeContext $context): ?array
    {
        if ($context->openTasks->isEmpty()) {
            return null;
        }

        $cards = $context->openTasks
            ->sortBy(fn (UserTask $ut) => $ut->deadline_status['days_remaining'] ?? 99999)
            ->take(8)
            ->values()
            ->map(function (UserTask $ut) {
                $status = $ut->deadline_status;
                $verifiedAt = $ut->task?->verified_at;

                return [
                    'id' => "task:{$ut->id}",
                    'name' => $ut->task?->title ?? 'Task',
                    'veedel' => null,
                    'category' => 'task',
                    'cost' => null,
                    'lat' => 0.0,
                    'lng' => 0.0,
                    'is_new' => false,
                    'kind' => 'task',
                    'href' => '/bureaucracy?focus='.($ut->task?->id ?? ''),
                    'reason' => null,
                    // The dedicated task-card fields, matching the prototype.
                    'due_label' => $status['label'],
                    'urgency' => $status['urgency'],
                    'verified' => $verifiedAt ? Carbon::parse($verifiedAt)->format('j M') : null,
                    'docs' => is_array($ut->task?->documents_required) ? count($ut->task->documents_required) : 0,
                ];
            })
            ->all();

        return $this->rail('paperwork', 'Get your paperwork moving', 'your checklist', '/bureaucracy', $cards);
    }

    /**
     * @param  list<array<string, mixed>>  $cards
     * @return array<string, mixed>
     */
    private function rail(string $key, string $title, string $reason, string $seeAll, array $cards): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'reason' => $reason,
            'see_all' => $seeAll,
            'cards' => $cards,
        ];
    }

    /**
     * The whole light spot catalogue, cached briefly — it's global (no per-user
     * data), so the scan is shared while scoring stays per-request.
     *
     * @return Collection<int, Spot>
     */
    private function spotPool(?float $originLat, ?float $originLng): Collection
    {
        // Cache plain rows (not Eloquent models) and re-hydrate — serialising
        // a model collection is fragile across cache drivers (it can come back
        // as __PHP_Incomplete_Class on a hit); arrays always round-trip.
        $columns = ['id', 'name', 'category', 'veedel', 'lat', 'lng', 'price_range', 'rating', 'photo_url', 'photo_attribution'];

        // No origin (GPS declined, nothing remembered): the old global, lowest-id
        // pool, cached once for everyone.
        if ($originLat === null || $originLng === null) {
            $rows = Cache::remember(self::SCAN_CACHE_KEY, self::SCAN_TTL, fn () => Spot::query()
                ->recommendationEligible()
                ->select($columns)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->orderBy('id')
                ->limit(self::POOL)
                ->get()
                ->toArray());

            return Spot::hydrate($rows);
        }

        // Nearest-first to the user, cached per ~1km cell (rounded so nearby
        // sessions share the scan). Proximity is resolved by the shared
        // NearbyPlaces service — the single source of the distance maths.
        $cellLat = round($originLat, 2);
        $cellLng = round($originLng, 2);

        $rows = Cache::remember(
            self::SCAN_CACHE_KEY.":{$cellLat},{$cellLng}",
            self::SCAN_TTL,
            fn () => $this->nearby->nearest($cellLat, $cellLng, self::POOL, null, $columns)->toArray(),
        );

        return Spot::hydrate($rows);
    }

    private function category(Spot $spot): string
    {
        return $spot->category instanceof \BackedEnum
            ? $spot->category->value
            : (string) $spot->category;
    }

    /**
     * The key a spot de-dups on across rails: its visible label (name +
     * Veedel), lower-cased. Falls back to the id for the rare nameless spot.
     */
    private function dedupKey(Spot $spot): string
    {
        $name = trim((string) $spot->name);

        return $name !== ''
            ? mb_strtolower($name).'|'.($spot->veedel ?? '')
            : 'id:'.$spot->id;
    }

    /**
     * Spot ids the user has taken out of discovery — "been" (consumed) or
     * "not interested" (rejected) — as an id-keyed set for O(1) rejection.
     *
     * @return array<int, int>
     */
    private function hiddenSpotIds(int $userId): array
    {
        return SpotFeedback::query()
            ->where('user_id', $userId)
            ->whereIn('state', array_map(
                fn (SpotFeedbackState $state) => $state->value,
                SpotFeedbackState::hiddenFromDiscovery(),
            ))
            ->pluck('spot_id')
            ->flip()
            ->all();
    }
}
