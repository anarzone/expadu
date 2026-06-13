<?php

namespace App\Home;

use App\Composer\IntentWeights;
use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Profile\ProfileEngine;
use App\Services\WeatherService;
use Illuminate\Support\Collection;

/**
 * The browse half of the home screen: Netflix-style discovery rails, ranked
 * by the SAME deterministic signals the composer uses — rating, learned
 * IntentWeights (category × Veedel), home-area match, and weather fit. No
 * ML, no embeddings, no LLM: the ranking skeleton that survived the pivot,
 * pointed at discovery. Each rail carries a contextual reason so the feed
 * reads as "because it's a rainy Sunday near you", never "because you
 * watched…".
 */
class DiscoveryFeed
{
    private const OUTDOOR = ['park', 'playground', 'pitch', 'basketball', 'lake', 'dog_park', 'bbq', 'viewpoint', 'skatepark', 'tennis', 'swimming'];

    private const KID_FRIENDLY = ['playground', 'park', 'swimming', 'lake', 'pitch', 'zoo', 'library'];

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

        $scored = Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByDesc('rating')
            ->limit(120)
            ->get()
            ->map(function (Spot $spot) use ($weights, $homeAreas, $rain) {
                $category = $this->category($spot);
                $key = "{$category}|".($spot->veedel ?? '');

                $score = (float) ($spot->rating ?? 0) * 2.0
                    + ($weights[$key] ?? 0.0) * 25.0
                    + (in_array($spot->veedel, $homeAreas, true) ? 8.0 : 0.0)
                    + ($rain && in_array($category, self::OUTDOOR, true) ? -15.0 : 0.0);

                return ['spot' => $spot, 'category' => $category, 'score' => $score];
            })
            ->sortByDesc('score')
            ->values();

        if ($scored->isEmpty()) {
            // No seeded spots yet — still surface events so the feed isn't blank.
            return array_values(array_filter([$this->tonightRail()]));
        }

        $rails = [];

        $rails[] = $this->spotRail(
            'made_for_today',
            $rain ? 'Made for a rainy day' : 'Made for today',
            $rain ? 'indoor · ranked for you' : 'ranked for your situation',
            $scored->take(10),
            $rain,
        );

        $home = $scored->filter(fn ($x) => in_array($x['spot']->veedel, $homeAreas, true))->take(10);
        if ($home->isNotEmpty()) {
            $rails[] = $this->spotRail('around_home', 'Around '.($profile->veedel ?? 'you'), 'your home area', $home, $rain, '📍 in your area');
        }

        if ($tonight = $this->tonightRail()) {
            $rails[] = $tonight;
        }

        $new = $scored->filter(fn ($x) => $x['spot']->veedel && ! in_array($x['spot']->veedel, $homeAreas, true))->take(10);
        if ($new->isNotEmpty()) {
            $rails[] = $this->spotRail('try_new', 'Try somewhere new', "a Veedel you haven't explored", $new, $rain, 'new to you', markNew: true);
        }

        return $rails;
    }

    /**
     * @param  Collection<int, array{spot: Spot, category: string, score: float}>  $scored
     * @return array<string, mixed>
     */
    private function spotRail(string $key, string $title, string $reason, $scored, bool $rain, ?string $cardReason = null, bool $markNew = false): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'reason' => $reason,
            'cards' => $scored->map(fn ($x) => [
                'id' => "spot:{$x['spot']->id}",
                'name' => $x['spot']->name,
                'veedel' => $x['spot']->veedel,
                'category' => $x['category'],
                'cost' => $x['spot']->price_range,
                'lat' => (float) $x['spot']->lat,
                'lng' => (float) $x['spot']->lng,
                'is_new' => $markNew,
                'reason' => $cardReason
                    ?? ($rain && in_array($x['category'], self::OUTDOOR, true) === false ? 'dry pick' : null),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tonightRail(): ?array
    {
        // lat/lng are ST_Y/ST_X accessors over the PostGIS `location` column,
        // so filter on the real column in SQL and on the accessor in PHP.
        $events = Event::query()
            ->whereDate('starts_at', today())
            ->where('starts_at', '>', now())
            ->whereNotNull('location')
            ->orderBy('starts_at')
            ->limit(20)
            ->get()
            ->filter(fn (Event $e) => $e->lat !== null && $e->lng !== null)
            ->take(10)
            ->values();

        if ($events->isEmpty()) {
            return null;
        }

        return [
            'key' => 'tonight',
            'title' => 'Tonight in Cologne',
            'reason' => 'curated · in your window',
            'cards' => $events->map(fn (Event $e) => [
                'id' => "event:{$e->id}",
                'name' => $e->title,
                'veedel' => $e->location_name,
                'category' => 'event',
                'cost' => $e->is_free ? 'free' : null,
                'lat' => (float) $e->lat,
                'lng' => (float) $e->lng,
                'is_new' => false,
                'reason' => $e->starts_at->format('H:i'),
            ])->values()->all(),
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
