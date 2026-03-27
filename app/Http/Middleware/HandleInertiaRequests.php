<?php

namespace App\Http\Middleware;

use App\Models\Event;
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
            'weather' => fn () => app(WeatherService::class)->getCurrentWeather(),
            'forecast' => fn () => app(WeatherService::class)->getForecast(),
            'userLocation' => function () use ($request) {
                $user = $request->user();
                if (! $user) {
                    return null;
                }
                $home = $user->places()->orderBy('sort_order')->first();

                if (! $home) {
                    return null; // No home location set — frontend should prompt user or use live GPS
                }

                return [
                    'name' => $home->name,
                    'address' => $home->address,
                    'lat' => $home->lat ? (float) $home->lat : null,
                    'lng' => $home->lng ? (float) $home->lng : null,
                ];
            },
            'todayEvents' => fn () => Event::query()
                ->whereDate('starts_at', today())
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->limit(3)
                ->get(['id', 'title', 'emoji', 'starts_at', 'location_name', 'is_free'])
                ->map(fn ($e) => [
                    'time' => $e->starts_at->format('H:i'),
                    'title' => ($e->emoji ?? '📅').' '.$e->title.' · '.$e->location_name,
                    'badge' => $e->is_free ? 'Open' : 'Paid',
                ]),
        ];
    }
}
