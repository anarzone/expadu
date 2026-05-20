<?php

namespace App\Http\Middleware;

use App\Models\NotificationPreference;
use App\Models\UserSetting;
use App\Services\UserLocationService;
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
            'features' => config('features'),
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
        ];
    }
}
