<?php

namespace App\Home;

use App\Models\Event;
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
    ) {}

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
