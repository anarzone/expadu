<?php

namespace App\Home;

use App\Bureaucracy\PathGenerator;
use App\Composer\IntentWeights;
use App\Composer\TodayPlanStore;
use App\Composer\TravelEstimator;
use App\Enums\TransportMode;
use App\Models\Event;
use App\Models\EventAttendee;
use App\Models\EventReminder;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserTask;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\Dto\GeoPoint;
use App\Transit\TravelTimes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Orchestrates the home feed: builds one shared HomeContext and hands it to the
 * three surfaces (chips, tiles, rails) in priority order, so a thing surfaced
 * as an urgent tile isn't repeated as a rail or chip. The context + tiles/rails
 * are memoised per request — the controller defers tiles() and rails()
 * separately, but within a request they resolve from a single build.
 */
class HomeFeed
{
    private ?HomeContext $context = null;

    private bool $built = false;

    /** @var list<array<string, mixed>> */
    private array $tiles = [];

    /** @var list<array<string, mixed>> */
    private array $rails = [];

    public function __construct(
        private readonly ProfileEngine $profiles,
        private readonly WeatherService $weather,
        private readonly IntentWeights $intents,
        private readonly TileComposer $tileComposer,
        private readonly DiscoveryFeed $discovery,
        private readonly PromptSuggestions $suggestions,
        private readonly PathGenerator $paths,
        private readonly UserLocationService $locations,
        private readonly TravelTimes $travel,
        private readonly TodayPlanStore $todayPlan,
    ) {}

    /**
     * @return list<array{label: string, prompt?: string, href?: string}>
     */
    public function chips(User $user): array
    {
        return $this->suggestions->for($this->context($user));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tiles(User $user): array
    {
        $this->build($user);

        return $this->tiles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rails(User $user): array
    {
        $this->build($user);

        return $this->rails;
    }

    private function build(User $user): void
    {
        if ($this->built) {
            return;
        }

        $context = $this->context($user);

        // Tiles first: they claim the urgent things, so rails (and the
        // chip rule) can skip what's already surfaced.
        $this->tiles = $this->tileComposer->tiles($context);
        $this->rails = $this->stampTravelMinutes($user, $context, $this->discovery->for($context));
        $this->built = true;
    }

    /**
     * Real "min away" on the feed's place cards — the same one-to-many street
     * matrix (mode-aware, MOTIS with haversine failover) Places uses, so a card
     * here and the same place there never disagree. One batched call for every
     * spot/event card across all rails; no origin (GPS declined, nothing
     * remembered) or an engine miss leaves travel_min null and the card simply
     * doesn't show a distance — never a guess.
     *
     * @param  list<array<string, mixed>>  $rails
     * @return list<array<string, mixed>>
     */
    private function stampTravelMinutes(User $user, HomeContext $context, array $rails): array
    {
        if (! $context->hasOrigin()) {
            return $rails;
        }

        // The one-to-many street matrix cannot calculate a transit journey.
        // Do not fill that gap with a straight-line transit guess: it can say
        // "13 min away" while a real departure takes an hour. A card simply
        // omits the duration until the user opens Take me there.
        if ($user->transport_mode === TransportMode::Transit) {
            return $rails;
        }

        $originLat = $context->originLat;
        $originLng = $context->originLng;

        // Collect unique destinations across every rail (tasks carry no coords).
        $destinations = [];
        foreach ($rails as $rail) {
            foreach ($rail['cards'] ?? [] as $card) {
                if (($card['lat'] ?? 0.0) !== 0.0 && ($card['lng'] ?? 0.0) !== 0.0) {
                    $destinations[$card['id']] = new GeoPoint((float) $card['lat'], (float) $card['lng']);
                }
            }
        }
        if ($destinations === []) {
            return $rails;
        }

        try {
            $minutes = $this->travel->minutes(
                $user->transport_mode,
                new GeoPoint($originLat, $originLng),
                array_values($destinations),
            );
        } catch (\Throwable) {
            $minutes = []; // feed must render even with the routing engine down
        }

        // Same fallback PlaceResource uses when the engine can't reach a
        // destination: a straight-line estimate in the user's mode.
        $byId = [];
        foreach (array_keys($destinations) as $i => $id) {
            $byId[$id] = $minutes[$i] ?? TravelEstimator::minutesFromKm(
                $this->kmBetween($originLat, $originLng, $destinations[$id]->lat, $destinations[$id]->lng),
                $user->transport_mode,
            );
        }

        foreach ($rails as $r => $rail) {
            foreach ($rail['cards'] ?? [] as $c => $card) {
                $rails[$r]['cards'][$c]['travel_min'] = $byId[$card['id']] ?? null;
            }
        }

        return $rails;
    }

    /** Great-circle distance in kilometres. */
    private function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($a)));
    }

    private function context(User $user): HomeContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $now = CarbonImmutable::now('Europe/Berlin');
        $forecast = $this->safeForecast();
        $profile = $this->profiles->build($user);

        // The shared origin (live / confirmed / remembered), resolved ONCE:
        // the discovery pool + proximity ranking and the travel-minutes stamp
        // both read it, so "nearby" is genuinely nearby and never re-resolved.
        $origin = $this->locations->context($user, request(), $user->veedel ?: null);

        // Quality-gated (same filter as the events page) so unvetted scraped rows
        // never surface as "Tonight"; curated expat-relevant events lead, soonest
        // first. Resolved before the DTO so the intended-event lookup can reuse it.
        $tonightEvents = Event::query()
            ->visible()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->whereNotNull('location')
            ->orderByDesc('is_curated')
            ->orderBy('starts_at')
            ->limit(20)
            ->get();

        // Which of tonight's events the user has signalled intent for (a reminder
        // or attendance) — the urgent tile fires only for these, not any event.
        $eventIds = $tonightEvents->pluck('id');
        $intendedEventIds = $eventIds->isEmpty() ? [] : EventReminder::query()
            ->where('user_id', $user->id)
            ->whereIn('event_id', $eventIds)
            ->pluck('event_id')
            ->merge(
                EventAttendee::query()
                    ->where('user_id', $user->id)
                    ->whereIn('event_id', $eventIds)
                    ->pluck('event_id')
            )
            ->unique()
            ->values()
            ->all();

        return $this->context = new HomeContext(
            userId: $user->id,
            profile: $profile,
            now: $now,
            originLat: $origin->hasOrigin() ? $origin->lat : null,
            originLng: $origin->hasOrigin() ? $origin->lng : null,
            // Only frame the feed as "rainy" when the forecast genuinely loaded
            // (broken/unavailable weather must never trigger it) AND rain falls
            // in the near-term window — `rain_soon`, the same window the weather
            // widget summarises. `rain_starts` alone pointed at any later hour of
            // the day, so a dry morning was wrongly framed "rainy, keep it cosy".
            rainExpected: ($forecast['available'] ?? false) && (bool) ($forecast['rain_soon'] ?? false),
            rainSummary: $forecast['rain_summary'] ?? null,
            intentWeights: $this->intents->for($user),
            isWeekendWindow: ($now->isFriday() && $now->hour >= 15) || $now->isSaturday() || $now->isSunday(),
            isEvening: $now->hour >= 17,
            openTasks: $this->applicableOpenTasks($user, $profile),
            tonightEvents: $tonightEvents,
            // "The user's day" for the fusion step: the pinned Today plan's slots
            // and the commutes (arrive_by places) that are relevant today.
            todayPlanSlots: $this->todayPlan->get($user)['slots'] ?? [],
            leaveByAnchors: UserPlace::query()
                ->where('user_id', $user->id)
                ->whereNotNull('arrive_by')
                ->get()
                ->filter->isActiveToday()
                ->values()
                ->all(),
            intendedEventIds: $intendedEventIds,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function safeForecast(): array
    {
        try {
            return $this->weather->getForecast();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Open tasks that DEFINITELY still apply. A user_task is never deleted on
     * recompute (progress is preserved), so a task whose applies_if now hinges
     * on an unanswered attribute (Unknown — a bureaucracy-page teaser) or no
     * longer matches the profile (No — "no longer relevant") can linger as an
     * open row. Only Yes reaches the urgent tile + paperwork rail, so a stale
     * conditional task can't sit "overdue by N days" on the home screen.
     *
     * @return Collection<int, UserTask>
     */
    private function applicableOpenTasks(User $user, Profile $profile): Collection
    {
        return UserTask::query()
            ->where('user_id', $user->id)
            ->open()
            ->notSnoozed()
            ->with('task')
            ->get()
            // deadline_status reads $ut->user for every task (tiles + paperwork
            // rail, several times each). They all belong to this one user, so set
            // the relation up front rather than lazy-loading it per task.
            ->each(fn (UserTask $ut) => $ut->setRelation('user', $user))
            ->filter(fn (UserTask $ut) => $ut->task !== null
                && $this->paths->applicability($ut->task, $profile) === Applicability::Yes)
            ->values();
    }
}
