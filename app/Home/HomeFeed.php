<?php

namespace App\Home;

use App\Bureaucracy\PathGenerator;
use App\Composer\IntentWeights;
use App\Models\Event;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
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
        $this->rails = $this->discovery->for($context);
        $this->built = true;
    }

    private function context(User $user): HomeContext
    {
        if ($this->context !== null) {
            return $this->context;
        }

        $now = CarbonImmutable::now('Europe/Berlin');
        $forecast = $this->safeForecast();
        $profile = $this->profiles->build($user);

        return $this->context = new HomeContext(
            userId: $user->id,
            profile: $profile,
            now: $now,
            rainExpected: (bool) ($forecast['rain_starts'] ?? false),
            rainSummary: $forecast['rain_summary'] ?? null,
            intentWeights: $this->intents->for($user),
            isWeekendWindow: ($now->isFriday() && $now->hour >= 15) || $now->isSaturday() || $now->isSunday(),
            isEvening: $now->hour >= 17,
            openTasks: $this->applicableOpenTasks($user, $profile),
            tonightEvents: Event::query()
                ->whereDate('starts_at', today())
                ->where('starts_at', '>', now())
                ->whereNotNull('location')
                ->orderBy('starts_at')
                ->limit(20)
                ->get(),
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
            ->filter(fn (UserTask $ut) => $ut->task !== null
                && $this->paths->applicability($ut->task, $profile) === Applicability::Yes)
            ->values();
    }
}
