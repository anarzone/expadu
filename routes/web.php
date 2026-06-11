<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\NearbyDeparturesController;
use App\Http\Controllers\Api\ReverseGeocodeController;
use App\Http\Controllers\Api\RouteOptionsController;
use App\Http\Controllers\Api\SpotSearchController;
use App\Http\Controllers\Api\StopSearchController;
use App\Http\Controllers\Api\TakeMeThereController;
use App\Http\Controllers\Api\TrackEventController;
use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeFeedController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfilePageController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\SlotMonitorController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\SpotController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserPlaceController;
use App\Http\Controllers\UserSettingController;
use App\Http\Controllers\UserTaskController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Subdomain Routing
|--------------------------------------------------------------------------
|
| Production: marketing routes on expadu.com, app routes on app.expadu.com.
| Local dev: APP_DOMAIN is null, so no subdomain enforcement — all routes
| respond to localhost.
|
*/

$appDomain = config('app.app_domain');
$marketingDomain = config('app.marketing_domain');

// ── Marketing site (expadu.com) ──────────────────────────────────────────
$marketingRoutes = function () {
    Route::inertia('/', 'welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ])->name('home');

    // Marketing pages will be built here (blog, FAQ, public events, etc.)
    // For now, only the welcome page exists. All other URLs on expadu.com return 404.
};

// ── App (app.expadu.com) ─────────────────────────────────────────────────
// Social login — responds on all domains (same as Fortify login/register)
Route::middleware('guest')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialLoginController::class, 'redirect'])->name('social.redirect');
    Route::get('auth/{provider}/callback', [SocialLoginController::class, 'callback'])->name('social.callback');
});

$appRoutes = function () use ($appDomain) {
    // App root redirects to dashboard (only in production with subdomain)
    if ($appDomain) {
        Route::get('/', fn () => redirect('/dashboard'));
    }

    Route::middleware(['auth', 'verified'])->group(function () {
        // APIs
        Route::get('api/geocode', GeocodeController::class)->name('api.geocode');
        Route::get('api/reverse-geocode', ReverseGeocodeController::class)->name('api.reverse-geocode');
        Route::get('api/stops', StopSearchController::class)->name('api.stops');
        Route::get('api/spots', SpotSearchController::class)->name('api.spots');
        Route::post('api/track', TrackEventController::class)->name('api.track');
        Route::get('api/route-options', RouteOptionsController::class)->name('api.route-options');
        Route::get('api/nearby-departures', NearbyDeparturesController::class)->name('api.nearby-departures');
        Route::get('api/journey', TakeMeThereController::class)->name('api.journey');

        Route::get('onboarding', fn () => Inertia::render('onboarding', [
            'veedels' => config('veedels'),
        ]))->name('onboarding');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

        Route::get('dashboard', HomeFeedController::class)->name('dashboard');

        // Explore / Spots
        Route::get('explore', [SpotController::class, 'index'])->name('explore');
        Route::get('explore/{spot}', [SpotController::class, 'show'])->name('spots.show');

        // Events
        Route::get('events', [EventController::class, 'index'])->name('events');
        Route::get('events/saved', [EventController::class, 'saved'])->name('events.saved');
        Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
        Route::post('events/{event}/join', [EventController::class, 'join'])->name('events.join');
        Route::delete('events/{event}/join', [EventController::class, 'leave'])->name('events.leave');

        // Alerts
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts');
        Route::post('alerts/{alert}/read', [AlertController::class, 'markRead'])->name('alerts.read');
        Route::post('alerts/read-all', [AlertController::class, 'markAllRead'])->name('alerts.read-all');
        Route::post('alerts/{alert}/dismiss', [AlertController::class, 'dismiss'])->name('alerts.dismiss');

        // Mute + thumbs-down (Roadmap #6 + #7)
        Route::get('mutes', [MuteController::class, 'index'])->name('mutes.index');
        Route::post('mutes', [MuteController::class, 'store'])->name('mutes.store');
        Route::delete('mutes', [MuteController::class, 'destroy'])->name('mutes.destroy');
        Route::post('mutes/thumbs-down', [MuteController::class, 'thumbsDown'])->name('mutes.thumbs-down');

        // Push subscriptions
        Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
        Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

        // Notification preferences
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

        // User settings
        Route::get('user-settings', [UserSettingController::class, 'show'])->name('user-settings.show');
        Route::put('user-settings', [UserSettingController::class, 'update'])->name('user-settings.update');

        // Spot reviews
        Route::get('explore/{spot}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
        Route::post('explore/{spot}/reviews', [ReviewController::class, 'store'])->name('reviews.store');

        // Slot monitoring
        Route::post('slots/toggle', [SlotMonitorController::class, 'toggle'])->name('slots.toggle');

        // User places
        Route::post('user-places', [UserPlaceController::class, 'store'])->name('user-places.store');
        Route::put('user-places/{userPlace}', [UserPlaceController::class, 'update'])->name('user-places.update');
        Route::delete('user-places/{userPlace}', [UserPlaceController::class, 'destroy'])->name('user-places.destroy');

        Route::get('services', [ServicesController::class, 'index'])->name('services');
        Route::get('bureaucracy', [BureaucracyController::class, 'index'])->name('bureaucracy');
        Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
        Route::post('tasks/{task}/report-outdated', [TaskController::class, 'reportOutdated'])->name('tasks.report-outdated');
        Route::patch('user-tasks/{userTask}', [UserTaskController::class, 'update'])->name('user-tasks.update');
        Route::get('profile', ProfilePageController::class)->name('profile');
    });
};

// Register routes — with or without subdomain enforcement
if ($appDomain && $marketingDomain) {
    // Production: enforce subdomains
    Route::domain($marketingDomain)->group($marketingRoutes);
    Route::domain($appDomain)->group($appRoutes);
} else {
    // Local dev: no subdomain enforcement, all routes on localhost
    $marketingRoutes();
    $appRoutes();
}

require __DIR__.'/settings.php';
