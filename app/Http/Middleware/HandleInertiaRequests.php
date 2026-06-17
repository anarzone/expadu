<?php

namespace App\Http\Middleware;

use App\Home\FeaturedEvent;
use App\Models\NotificationPreference;
use App\Models\UserSetting;
use App\Services\DisruptionService;
use App\Services\RhineService;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
            'serviceErrors' => fn () => session('serviceErrors', []),
            'notificationPreferences' => fn () => $request->user()?->notificationPreference?->preferences ?? NotificationPreference::defaults(),
            'userSettings' => fn () => $request->user()?->userSetting?->settings ?? UserSetting::defaults(),
            // User location — cached per request to avoid redundant places queries
            'userLocation' => function () use ($request) {
                $user = $request->user();
                if (! $user) {
                    return null;
                }

                return app(UserLocationService::class)->resolve($user, $request);
            },
            // Right-panel city widgets. Deferred (never block first paint) and
            // shared so every page that shows the RightPanel — Today, Composer,
            // Places — is fed, not just the dashboard. The v2 rewrite dropped
            // these props but left the widgets reading them, which is why they
            // read "unavailable" everywhere.
            'weather' => Inertia::defer(fn () => $request->user() ? app(WeatherService::class)->getCurrentWeather() : null, 'widgets'),
            'forecast' => Inertia::defer(fn () => $request->user() ? app(WeatherService::class)->getForecast() : null, 'widgets'),
            'rhineLevel' => Inertia::defer(fn () => $request->user() ? app(RhineService::class)->getCurrentLevel() : null, 'widgets'),
            'featuredEvent' => Inertia::defer(fn () => $request->user() ? app(FeaturedEvent::class)->forToday() : null, 'widgets'),
            'activeDisruptions' => Inertia::defer(fn () => $request->user() ? $this->activeDisruptions() : [], 'widgets'),
        ];
    }

    /**
     * Today's curated events for the right-panel widget.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Active transit/city disruptions for the right-panel widget (top 2).
     *
     * @return list<array<string, mixed>>
     */
    private function activeDisruptions(): array
    {
        return collect(app(DisruptionService::class)->getLineDisruptions())
            ->map(fn (array $d) => [
                'title' => $d['title'],
                'severity' => $d['severity'],
                'lines' => $d['affected_lines'],
            ])
            ->take(2)
            ->values()
            ->all();
    }
}
