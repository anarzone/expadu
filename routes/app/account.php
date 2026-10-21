<?php

/*
| The account and its utility pages (session 1): onboarding, alerts,
| preferences, places, the situation profile, and the dev references.
| Loaded inside the app's auth + verified + app-writes group in
| routes/web.php.
*/

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api\LocationConfirmController;
use App\Http\Controllers\Api\TrackEventController;
use App\Http\Controllers\Api\TransportModeController;
use App\Http\Controllers\EmailConfirmationController;
use App\Http\Controllers\MuteController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfilePageController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\UserPlaceController;
use App\Http\Controllers\UserSettingController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::post('api/track', TrackEventController::class)->name('api.track');
Route::post('api/location/confirm', LocationConfirmController::class)->name('api.location.confirm');
Route::post('api/preferences/transport-mode', TransportModeController::class)->name('api.preferences.transport-mode');

Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');
Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
Route::post('onboarding/restart', [OnboardingController::class, 'restart'])->name('onboarding.restart');

// Confirm it's you with an emailed code, where password.confirm asks an
// account that has no password (EXP-174).
Route::post('user/confirm-email/code', [EmailConfirmationController::class, 'send'])
    ->middleware('throttle:email-confirmation')->name('email-confirmation.send');
Route::post('user/confirm-email', [EmailConfirmationController::class, 'confirm'])
    ->middleware('throttle:10,1')->name('email-confirmation.confirm');

// Card showcase — dev-only reference for ContentCard variants
if (! app()->environment('production')) {
    Route::get('dev/cards', fn () => Inertia::render('dev/cards'))->name('dev.cards');
}

// The shared primitives (components/ui), for checking against the mock —
// local + staging only, like the design-system reference below.
Route::get('dev/primitives', function () {
    abort_if(
        app()->isProduction() && ! str_contains((string) request()->getHost(), 'staging'),
        404,
    );

    return Inertia::render('dev/primitives');
})->name('dev.primitives');

// Design-system reference — visible on local + staging, hidden on
// production. Staging runs APP_ENV=production, so gate on the host.
Route::get('dev/design-system', function () {
    abort_if(
        app()->isProduction() && ! str_contains((string) request()->getHost(), 'staging'),
        404,
    );

    return Inertia::render('dev/design-system');
})->name('dev.design-system');

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
Route::post('push/status', [PushSubscriptionController::class, 'status'])->name('push.status');
Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

// Notification preferences
Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

// User settings
Route::get('user-settings', [UserSettingController::class, 'show'])->name('user-settings.show');
Route::put('user-settings', [UserSettingController::class, 'update'])->name('user-settings.update');

// User places
Route::post('user-places', [UserPlaceController::class, 'store'])->name('user-places.store');
Route::put('user-places/{userPlace}', [UserPlaceController::class, 'update'])->name('user-places.update');
Route::delete('user-places/{userPlace}', [UserPlaceController::class, 'destroy'])->name('user-places.destroy');

// Services stays hidden from navigation (d38295bf).
Route::get('services', [ServicesController::class, 'index'])->name('services');

Route::get('profile', ProfilePageController::class)->name('profile');
