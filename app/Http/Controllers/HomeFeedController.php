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

        return Inertia::render('dashboard', [
            'feed' => fn () => $recommendationService->buildDashboardFeed($user, $request),
            'commuteRecommendation' => fn () => $recommendationService->getCommuteRecommendation($user),
            // Right panel + weather widget data
            'weather' => fn () => app(WeatherService::class)->getCurrentWeather($lat, $lng),
            'forecast' => fn () => app(WeatherService::class)->getForecast($lat, $lng),
            'rhineLevel' => fn () => app(RhineService::class)->getCurrentLevel(),
            'todayEvents' => fn () => Event::query()
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
                ->all(),
            'activeDisruptions' => fn () => collect(app(DisruptionService::class)->getLineDisruptions())
                ->map(fn ($d) => ['title' => $d['title'], 'severity' => $d['severity'], 'lines' => $d['affected_lines']])
                ->take(5)->values()->all(),
        ]);
    }
}
