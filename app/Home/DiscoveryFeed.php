<?php

namespace App\Home;

use App\Composer\CategoryAppeal;
use App\Composer\IntentWeights;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserTask;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The browse half of the home screen: Netflix-style discovery rails, ranked
 * by deterministic signals — category appeal (activities lead, cafés don't),
 * learned IntentWeights, home-area match, and weather fit. No ML, no LLM.
 * Ratings are only a tiebreaker: they exist mostly for commercial venues, so
 * ranking by them would bury the actual things-to-do. Each rail carries a
 * personal, contextual title ("Made for your rainy Sunday").
 */
class DiscoveryFeed
{
    private const OUTDOOR = ['park', 'playground', 'pitch', 'basketball', 'lake', 'dog_park', 'bbq', 'viewpoint', 'skatepark', 'tennis', 'table_tennis', 'boules', 'swimming'];

    /** Pull the whole light catalogue so every category can compete. */
    private const POOL = 2000;

    /** Cap on sit-down/work venues per rail, so a rail isn't all cafés. */
    private const LOW_APPEAL_CAP = 2;

    /** Cap per category per rail, so a rail isn't 12 identical playgrounds. */
    private const CAP_PER_CATEGORY = 2;

    public function __construct(
        private readonly IntentWeights $intents,
        private readonly ProfileEngine $profiles,
        private readonly WeatherService $weather,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(User $user): array
    {
        $profile = $this->profiles->build($user);
        $weights = $this->intents->for($user);
        $rain = $this->rainExpected();
        $homeAreas = $profile->defaultAreas;
        $weekday = CarbonImmutable::now('Europe/Berlin')->format('l');

        // Pull the whole catalogue (light columns) so culture/indoor competes
        // with activities — capping by category earlier hid museums entirely.
        // Appeal + weather scoring then orders it; pickCards diversifies.
        $scored = Spot::query()
            ->select('id', 'name', 'category', 'veedel', 'lat', 'lng', 'price_range', 'rating')
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->limit(self::POOL)
            ->get()
            ->map(fn (Spot $spot) => $this->scoreSpot($spot, $weights, $homeAreas, $rain))
            ->sortByDesc('score')
            ->values();

        $rails = array_values(array_filter([
            $scored->isNotEmpty()
                ? $this->rail(
                    'made_for_today',
                    $rain ? "Made for your rainy {$weekday}" : "Made for your {$weekday}",
                    'ranked for you',
                    '/explore',
                    $this->pickCards($scored, 12, null, false, $rain),
                )
                : null,
            $this->tonightRail(),
            $this->homeRail($scored, $homeAreas, $profile->veedel, $rain),
            $this->newRail($scored, $homeAreas, $rain),
            $this->paperworkRail($user),
        ]));

        return $rails;
    }

    /**
     * @param  array<string, float>  $weights
     * @param  list<string>  $homeAreas
     * @return array{spot: Spot, category: string, score: float}
     */
    private function scoreSpot(Spot $spot, array $weights, array $homeAreas, bool $rain): array
    {
        $category = $this->category($spot);
        $key = "{$category}|".($spot->veedel ?? '');

        $score = CategoryAppeal::score($category) * 40.0
            + ($weights[$key] ?? 0.0) * 25.0
            + (in_array($spot->veedel, $homeAreas, true) ? 8.0 : 0.0)
            + ($rain && in_array($category, self::OUTDOOR, true) ? -25.0 : 0.0)
            + (float) ($spot->rating ?? 0) * 0.5; // gentle tiebreak only

        return ['spot' => $spot, 'category' => $category, 'score' => $score];
    }

    /**
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     */
    private function homeRail(Collection $scored, array $homeAreas, ?string $veedel, bool $rain): ?array
    {
        $home = $scored->filter(fn ($x) => in_array($x['spot']->veedel, $homeAreas, true));
        if ($home->isEmpty()) {
            return null;
        }

        return $this->rail('around_home', 'Around '.($veedel ?? 'you'), 'your home area', '/explore',
            $this->pickCards($home, 12, '📍 in your area', false, $rain));
    }

    /**
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     */
    private function newRail(Collection $scored, array $homeAreas, bool $rain): ?array
    {
        $new = $scored->filter(fn ($x) => $x['spot']->veedel && ! in_array($x['spot']->veedel, $homeAreas, true));
        if ($new->isEmpty()) {
            return null;
        }

        return $this->rail('try_new', 'Somewhere new for you', "a corner you haven't explored", '/explore',
            $this->pickCards($new, 12, 'new to you', true, $rain));
    }

    /**
     * Pick cards for a rail, capping low-appeal (café/restaurant/bar/cowork)
     * so a rail never becomes a coffee list.
     *
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @return list<array<string, mixed>>
     */
    private function pickCards(Collection $scored, int $limit, ?string $cardReason, bool $markNew, bool $rain): array
    {
        $cards = [];
        $lowAppeal = 0;
        $perCategory = [];

        foreach ($scored as $x) {
            if (count($cards) >= $limit) {
                break;
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
            ];
        }

        return $cards;
    }

    private function tonightRail(): ?array
    {
        $events = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->whereNotNull('location')
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->filter(fn (Event $e) => $e->lat !== null && $e->lng !== null)
            ->take(12)
            ->values();

        if ($events->isEmpty()) {
            return null;
        }

        return $this->rail('tonight', 'Tonight in Cologne', 'in your window', '/events',
            $events->map(fn (Event $e) => [
                'id' => "event:{$e->id}",
                'name' => $e->title,
                'veedel' => $e->location_name,
                'category' => 'event',
                'cost' => $e->is_free ? 'free' : null,
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'is_new' => false,
                'kind' => 'event',
                'href' => null,
                'reason' => $e->starts_at->format('H:i'),
            ])->all());
    }

    private function paperworkRail(User $user): ?array
    {
        $tasks = UserTask::query()
            ->where('user_id', $user->id)
            ->open()
            ->notSnoozed()
            ->with('task')
            ->get();

        if ($tasks->isEmpty()) {
            return null;
        }

        $cards = $tasks
            ->sortBy(fn (UserTask $ut) => $ut->deadline_status['days_remaining'] ?? 99999)
            ->take(8)
            ->values()
            ->map(fn (UserTask $ut) => [
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
                'reason' => $ut->deadline_status['label'] ?? null,
            ])
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

    private function category(Spot $spot): string
    {
        return $spot->category instanceof \BackedEnum
            ? $spot->category->value
            : (string) $spot->category;
    }

    private function rainExpected(): bool
    {
        try {
            return (bool) ($this->weather->getForecast()['rain_starts'] ?? false);
        } catch (\Throwable) {
            return false;
        }
    }
}
