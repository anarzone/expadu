<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\DisruptionService;
use App\Services\HomeFeedComposer;
use App\Services\RhineService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, HomeFeedComposer $composer): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);
        $lat = $location['lat'];
        $lng = $location['lng'];

        $weatherService = app(WeatherService::class);

        return Inertia::render('dashboard', [
            // Heavy feed: own defer group so it doesn't block the meta group.
            'feed' => Inertia::defer(fn () => $composer->buildDashboardFeed($user, $request)),
            // Commute + weather group: depends on routing/external providers, batched together.
            'commuteRecommendation' => Inertia::defer(fn () => $composer->buildCommuteRecommendation($user), 'commute'),
            'weather' => Inertia::defer(fn () => $weatherService->getCurrentWeather($lat, $lng), 'commute'),
            'forecast' => Inertia::defer(fn () => $weatherService->getForecast($lat, $lng), 'commute'),
            // Light meta group: small queries / fast service hits.
            'rhineLevel' => Inertia::defer(fn () => app(RhineService::class)->getCurrentLevel(), 'meta'),
            'todayEvents' => Inertia::defer(fn () => Event::query()
                ->whereDate('starts_at', today())
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->limit(7)
                ->get(['id', 'title', 'starts_at', 'location_name', 'is_free', 'category'])
                ->map(fn ($e) => [
                    'time' => $e->starts_at->format('H:i'),
                    'emoji' => match ($e->category) {
                        'music' => '🎵', 'sports' => '⚽', 'language' => '🗣️', 'culture' => '🎭', 'community' => '🤝', 'food' => '🍽️', 'market' => '🛒', default => '📅'
                    },
                    'title' => $e->title,
                    'location' => $e->location_name,
                    'badge' => $e->is_free ? 'Free' : ucfirst($e->category ?? 'Event'),
                    'badgeType' => $e->is_free ? 'free' : 'category',
                ])
                ->all(), 'meta'),
            'activeDisruptions' => Inertia::defer(fn () => collect(app(DisruptionService::class)->getLineDisruptions())
                ->map(fn ($d) => ['title' => $d['title'], 'severity' => $d['severity'], 'lines' => $d['affected_lines']])
                ->take(5)->values()->all(), 'meta'),
        ]);
    }
}
