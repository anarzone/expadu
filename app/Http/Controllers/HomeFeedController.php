<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\DisruptionService;
use App\Services\RecommendationService;
use App\Services\RhineService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeFeedController extends Controller
{
    public function __invoke(Request $request, RecommendationService $recommendationService): Response
    {
        $user = $request->user();
        $location = app(UserLocationService::class)->resolve($user, $request);
        $lat = $location['lat'];
        $lng = $location['lng'];

        $weatherService = app(WeatherService::class);

        return Inertia::render('dashboard', [
            'feed' => Inertia::defer(fn () => $recommendationService->buildDashboardFeed($user, $request)),
            'commuteRecommendation' => Inertia::defer(fn () => $recommendationService->getCommuteRecommendation($user)),
            'weather' => Inertia::defer(fn () => $weatherService->getCurrentWeather($lat, $lng), 'weather'),
            'forecast' => Inertia::defer(fn () => $weatherService->getForecast($lat, $lng), 'weather'),
            'rhineLevel' => Inertia::defer(fn () => app(RhineService::class)->getCurrentLevel(), 'extras'),
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
                ->all(), 'extras'),
            'activeDisruptions' => Inertia::defer(fn () => collect(app(DisruptionService::class)->getLineDisruptions())
                ->map(fn ($d) => ['title' => $d['title'], 'severity' => $d['severity'], 'lines' => $d['affected_lines']])
                ->take(5)->values()->all(), 'extras'),
        ]);
    }
}
