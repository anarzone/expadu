<?php

namespace App\Home;

use App\Models\Event;
use App\Models\UserPlace;
use App\Models\UserTask;
use App\Profile\Profile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A compute-once snapshot of every signal the home-feed surfaces read. Built a
 * single time per request by HomeFeed and shared by the chips, tiles and rails
 * so they stay consistent — and can dedupe against each other — instead of each
 * re-fetching weather/weights and racing to surface the same thing.
 */
class HomeContext
{
    /** @var array<string, true> entity keys (e.g. "event:42") a higher-priority surface has claimed */
    private array $claimed = [];

    /**
     * @param  array<string, float>  $intentWeights  "category|veedel" => weight
     * @param  Collection<int, UserTask>  $openTasks
     * @param  Collection<int, Event>  $tonightEvents  today's still-upcoming events, soonest first
     */
    public function __construct(
        public readonly int $userId,
        public readonly Profile $profile,
        public readonly CarbonImmutable $now,
        public readonly bool $rainExpected,
        public readonly ?string $rainSummary,
        public readonly array $intentWeights,
        public readonly bool $isWeekendWindow,
        public readonly bool $isEvening,
        public readonly Collection $openTasks,
        public readonly Collection $tonightEvents,
        // Where the user is right now (shared origin: live/confirmed/remembered),
        // null when unknown. The feed uses it so "nearby" is genuinely nearby —
        // the pool is the nearest spots and proximity ranks them.
        public readonly ?float $originLat = null,
        public readonly ?float $originLng = null,
        // "The user's day", read by the fusion step so a world-signal (rain, a
        // disruption) can be tested against what they're actually doing — the
        // difference between "it will rain" and "your plan will get wet".
        /** @var list<array<string, mixed>> slots of the pinned Today plan (TodayPlanStore) */
        public readonly array $todayPlanSlots = [],
        /** @var list<UserPlace> places with an arrive_by that are active today */
        public readonly array $leaveByAnchors = [],
        /** @var list<int> ids of today's events the user has a reminder or attendance for */
        public readonly array $intendedEventIds = [],
    ) {}

    public function hasOrigin(): bool
    {
        return $this->originLat !== null && $this->originLng !== null;
    }

    /**
     * Whether the user has a commute today — a place with an arrive_by that is
     * active now. This is what turns "a line you ride is disrupted" (ambient)
     * into "your route today is disrupted, leave early" (act-now).
     */
    public function hasLiveCommuteToday(): bool
    {
        return $this->leaveByAnchors !== [];
    }

    /**
     * The label of an outdoor exposure — a pinned outdoor plan stop, or a commute
     * the user is still heading out for — happening at or after `$time`, or null
     * if the day holds nothing the weather would spoil. This is what turns a rain
     * advisory into a plan-at-risk need (or lets it stay off Today entirely).
     */
    public function outdoorExposureAfter(CarbonImmutable $time): ?string
    {
        foreach ($this->todayPlanSlots as $slot) {
            if (! ($slot['outdoor'] ?? false) || empty($slot['start_at'])) {
                continue;
            }
            if (CarbonImmutable::parse($slot['start_at'])->gte($time)) {
                return is_string($slot['name'] ?? null) ? $slot['name'] : 'your plan';
            }
        }

        foreach ($this->leaveByAnchors as $anchor) {
            $arriveBy = $anchor->arrive_by ?? null;
            if (! is_string($arriveBy) || $arriveBy === '') {
                continue;
            }
            if ($this->now->setTimeFromTimeString($arriveBy)->gte($time)) {
                return $anchor->name ? "your trip to {$anchor->name}" : 'your commute';
            }
        }

        return null;
    }

    public function hasEventsTonight(): bool
    {
        return $this->tonightEvents->isNotEmpty();
    }

    /** Mark an entity as surfaced so a lower-priority surface skips it. */
    public function claim(string $key): void
    {
        $this->claimed[$key] = true;
    }

    public function isClaimed(string $key): bool
    {
        return isset($this->claimed[$key]);
    }
}
