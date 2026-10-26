<?php

namespace App\Http\Middleware;

use App\Models\ActiveTrip;
use App\Models\UserSetting;
use App\Personalisation\PersonalisationWording;
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
            'auth' => [
                'user' => $request->user(),
                // Which "confirm it's you" to offer: the password, or an
                // emailed code (EXP-174). Nothing else about the password.
                'has_password' => $request->user() !== null && $request->user()->password !== null,
            ],
            // Admin-only QA tooling (Events' motion lab), for admins and on
            // this machine only, gated off a single prop.
            'qaSwitcher' => (bool) ($request->user()?->is_admin || app()->environment('local')),
            // One-shot flash messages — surfaced globally as a toast so no
            // redirect (e.g. "you're already signed in") is ever silent.
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
            ],
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            'unreadAlertCount' => fn () => $request->user()?->alerts()->publishable()->whereNull('dismissed_at')->whereNull('read_at')->count() ?? 0,
            // Settings saved before a key existed read it at its default.
            'userSettings' => fn () => [...UserSetting::defaults(), ...($request->user()?->userSetting?->settings ?? []),
                'personalisation_current' => $request->user() === null || PersonalisationWording::current($request->user())],
            // The person's own saved needs, for the account menu's switch
            // (needs_step_free stays out of the user's serialisation).
            'needs' => fn () => $request->user() === null ? null : [
                'step_free' => (bool) $request->user()->needs_step_free,
            ],
            // The user's live trip (if any) — powers the app-wide "trip in
            // progress" banner and lets Departures reopen the journey. Cheap
            // indexed lookup; present on first paint so the banner never flickers.
            // A finished trip isn't shown and is deleted on the way, so where
            // it started isn't kept past the trip (ActiveTrip::currentFor).
            'activeTrip' => fn () => ActiveTrip::currentFor($request->user())?->toShared(),
            ...$this->widgets($request),
        ];
    }

    /**
     * The city's weather and forecast (all Today still reads of the old
     * right-panel widgets), in every page's first response: both are cache
     * reads (WeatherService never fetches on a request), so a page needs no
     * second round trip for them (owner, 26 Oct 2026: pages load whole).
     * City-wide, the same for everyone.
     *
     * Signed-in only, as the pages that show them are.
     *
     * @return array<string, mixed>
     */
    private function widgets(Request $request): array
    {
        if (! $request->user()) {
            return [];
        }

        return [
            'weather' => fn () => app(WeatherService::class)->getCurrentWeather(),
            'forecast' => fn () => app(WeatherService::class)->getForecast(),
        ];
    }
}
