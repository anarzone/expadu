<?php

namespace App\Http\Middleware;

use App\Models\Event;
use App\Services\DisruptionService;
use App\Services\RhineService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'isOnboarded' => $request->user()?->isOnboarded() ?? false,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'unreadAlertCount' => fn () => $request->user()?->alerts()->whereNull('read_at')->count() ?? 0,
            'userLocation' => function () use ($request) {
                $user = $request->user();
                if (! $user) {
                    return null;
                }
                $home = $user->places()->orderBy('sort_order')->first();

                if (! $home) {
                    return null;
                }

                return [
                    'name' => $home->name,
                    'address' => $home->address,
                    'lat' => $home->lat ? (float) $home->lat : null,
                    'lng' => $home->lng ? (float) $home->lng : null,
                ];
            },
            'weather' => function () use ($request) {
                $user = $request->user();
                $home = $user?->places()->orderBy('sort_order')->first();
                $lat = $home?->lat ? (float) $home->lat : 50.9375;
                $lng = $home?->lng ? (float) $home->lng : 6.9603;

                return app(WeatherService::class)->getCurrentWeather($lat, $lng);
            },
            'forecast' => function () use ($request) {
                $user = $request->user();
                $home = $user?->places()->orderBy('sort_order')->first();
                $lat = $home?->lat ? (float) $home->lat : 50.9375;
                $lng = $home?->lng ? (float) $home->lng : 6.9603;

                return app(WeatherService::class)->getForecast($lat, $lng);
            },
            'todayEvents' => fn () => Event::query()
                ->whereDate('starts_at', today())
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->limit(7)
                ->get(['id', 'title', 'starts_at', 'location_name', 'is_free', 'category'])
                ->map(fn ($e) => [
                    'time' => $e->starts_at->format('H:i'),
                    'emoji' => match ($e->category) {
                        'music' => '🎵',
                        'sports' => '⚽',
                        'language' => '🗣️',
                        'culture' => '🎭',
                        'community' => '🤝',
                        'food' => '🍽️',
                        'market' => '🛒',
                        default => '📅',
                    },
                    'title' => $e->title,
                    'location' => $e->location_name,
                    'badge' => $e->is_free ? 'Free' : match ($e->category) {
                        'music' => 'Music',
                        'sports' => 'Sports',
                        'language' => 'Language',
                        'culture' => 'Culture',
                        'community' => 'Community',
                        'food' => 'Food',
                        default => 'Event',
                    },
                    'badgeType' => $e->is_free ? 'free' : 'category',
                ]),
            'rhineLevel' => fn () => app(RhineService::class)->getCurrentLevel(),
            'activeDisruptions' => fn () => collect(app(DisruptionService::class)->getLineDisruptions())
                ->map(fn ($d) => [
                    'title' => $d['title'],
                    'severity' => $d['severity'],
                    'lines' => $d['affected_lines'],
                ])
                ->take(5)
                ->values()
                ->all(),
        ];
    }
}
