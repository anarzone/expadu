<?php

namespace App\Http\Middleware;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\OpenTaskCount;
use App\Home\FeaturedEvent;
use App\Models\ActiveTrip;
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
            // Admin-only QA tooling: the floating "become this persona"
            // toolbar. Null for everyone else so PersonaSwitcher can gate off
            // a single prop instead of shipping a separate admin flag.
            'qaSwitcher' => ($request->user()?->is_admin || app()->environment('local'))
                ? [
                    // data_get: profile_attributes is nullable (e.g. the admin
                    // account), so a raw ['qa_persona'] offset would warn per request.
                    'current' => data_get($request->user()?->profile_attributes, 'qa_persona'),
                    'personas' => collect(BureaucracyPersonas::demo())
                        ->map(fn (array $p) => ['key' => $p['key'], 'label' => $p['label']])
                        ->values()->all(),
                ]
                : null,
            // One-shot flash messages — surfaced globally as a toast so no
            // redirect (e.g. "you're already signed in") is ever silent.
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'unreadAlertCount' => fn () => $request->user()?->alerts()->publishable()->whereNull('dismissed_at')->whereNull('read_at')->count() ?? 0,
            // The sidebar's Bureaucracy badge: open action tasks that need
            // attention now (overdue or due within two weeks), never a fixed
            // placeholder. See OpenTaskCount for why it isn't "every open task".
            'bureaucracyAttentionCount' => fn () => $request->user()
                ? app(OpenTaskCount::class)->forUser($request->user())
                : 0,
            'serviceErrors' => fn () => session('serviceErrors', []),
            'notificationPreferences' => fn () => $request->user()?->notificationPreference?->preferences ?? NotificationPreference::defaults(),
            'userSettings' => fn () => $request->user()?->userSetting?->settings ?? UserSetting::defaults(),
            // The user's live trip (if any) — powers the app-wide "trip in
            // progress" banner and lets Departures reopen the journey. Cheap
            // indexed lookup; present on first paint so the banner never flickers.
            // A finished trip isn't shown and is deleted on the way, so where
            // it started isn't kept past the trip (ActiveTrip::currentFor).
            'activeTrip' => fn () => ActiveTrip::currentFor($request->user())?->toShared(),
            // User location — only a real, confirmed or recently accepted
            // origin is shared. Never invent a home or city-centre position.
            'userLocation' => function () use ($request) {
                $user = $request->user();
                if (! $user) {
                    return null;
                }

                $location = app(UserLocationService::class)->context($user, $request);

                return $location->hasOrigin() ? [
                    'lat' => $location->lat,
                    'lng' => $location->lng,
                    'source' => $location->source->value,
                    'name' => $location->label,
                ] : null;
            },
            ...$this->widgets($request),
        ];
    }

    /**
     * Right-panel city widgets. Deferred (never block first paint) and shared
     * so every page that shows the RightPanel — Today, Composer, Places — is
     * fed, not just the dashboard.
     *
     * Once-props: the client keeps them across navigations and tells the server
     * so, which then skips the widgets request until they lapse. They used to
     * be re-fetched — a second request re-running the page controller — on
     * every visit. Five minutes matches the services' own fresh-cache TTL, and
     * the data is city-wide, so it is the same for every user.
     *
     * Signed-in only: a guest page would otherwise hand the client nulls it
     * keeps for five minutes, leaving the panel empty right after login.
     *
     * @return array<string, mixed>
     */
    private function widgets(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        $until = now()->addMinutes(5);
        $widget = fn (callable $resolve) => Inertia::defer($resolve, 'widgets')->once(until: $until);

        return [
            'weather' => $widget(fn () => app(WeatherService::class)->getCurrentWeather()),
            'forecast' => $widget(fn () => app(WeatherService::class)->getForecast()),
            'rhineLevel' => $widget(fn () => app(RhineService::class)->getCurrentLevel()),
            'featuredEvent' => $widget(fn () => app(FeaturedEvent::class)->forToday()),
            'activeDisruptions' => $widget(fn () => $this->activeDisruptions()),
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
