<?php

namespace App\Http\Middleware;

use App\Models\ActiveTrip;
use App\Models\NotificationPreference;
use App\Models\UserSetting;
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
                // Which "confirm it's you" to offer: the password, or an
                // emailed code (EXP-174). Nothing else about the password.
                'has_password' => $request->user() !== null && $request->user()->password !== null,
            ],
            'isOnboarded' => $request->user()?->isOnboarded() ?? false,
            // Admin-only QA tooling: the floating "become this persona"
            // toolbar. Null for everyone else so PersonaSwitcher can gate off
            // a single prop instead of shipping a separate admin flag.
            // The QA tools show for admins and on this machine only.
            'qaSwitcher' => (bool) ($request->user()?->is_admin || app()->environment('local')),
            // One-shot flash messages — surfaced globally as a toast so no
            // redirect (e.g. "you're already signed in") is ever silent.
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'unreadAlertCount' => fn () => $request->user()?->alerts()->publishable()->whereNull('dismissed_at')->whereNull('read_at')->count() ?? 0,
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
     * The city's weather and forecast (all Today still reads of the old
     * right-panel widgets). Deferred (never block first paint) and shared.
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
        ];
    }
}
