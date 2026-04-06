<?php

use App\Http\Controllers\AlertController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\NearbyDeparturesController;
use App\Http\Controllers\Api\RouteOptionsController;
use App\Http\Controllers\Api\SpotSearchController;
use App\Http\Controllers\Api\StopSearchController;
use App\Http\Controllers\Api\TrackEventController;
use App\Http\Controllers\Api\TransferConnectionsController;
use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\HomeFeedController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfilePageController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\ServicesController;
use App\Http\Controllers\SlotMonitorController;
use App\Http\Controllers\SpotController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TransitController;
use App\Http\Controllers\UserPlaceController;
use App\Http\Controllers\UserSettingController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // APIs — generous limits since all are authenticated + local data
    Route::get('api/geocode', GeocodeController::class)->name('api.geocode');
    Route::get('api/stops', StopSearchController::class)->name('api.stops');
    Route::get('api/spots', SpotSearchController::class)->name('api.spots');
    Route::post('api/track', TrackEventController::class)->name('api.track');
    Route::get('api/route-options', RouteOptionsController::class)->name('api.route-options');
    Route::get('api/nearby-departures', NearbyDeparturesController::class)->name('api.nearby-departures');
    Route::get('api/transfer-connections', [TransferConnectionsController::class, 'getConnections'])->name('api.transfer-connections');
    Route::post('api/transfer-select', [TransferConnectionsController::class, 'selectConnection'])->name('api.transfer-select');

    Route::inertia('onboarding', 'onboarding')->name('onboarding');
    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    Route::get('dashboard', HomeFeedController::class)->name('dashboard');

    // Transit
    Route::get('transit', [TransitController::class, 'index'])->name('transit');
    Route::post('routines', [RoutineController::class, 'store'])->name('routines.store');
    Route::put('routines/{routine}', [RoutineController::class, 'update'])->name('routines.update');
    Route::delete('routines/{routine}', [RoutineController::class, 'destroy'])->name('routines.destroy');

    // Explore / Spots
    Route::get('explore', [SpotController::class, 'index'])->name('explore');
    Route::get('explore/{spot}', [SpotController::class, 'show'])->name('spots.show');
    Route::post('explore/{spot}/checkin', [SpotController::class, 'checkin'])->name('spots.checkin');
    Route::post('explore/{spot}/checkout', [SpotController::class, 'checkout'])->name('spots.checkout');

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

    // Push subscriptions
    Route::post('push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::post('push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    // Notification preferences
    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])->name('notification-preferences.update');

    // User settings (privacy, etc.)
    Route::get('user-settings', [UserSettingController::class, 'show'])->name('user-settings.show');
    Route::put('user-settings', [UserSettingController::class, 'update'])->name('user-settings.update');

    // Spot reviews
    Route::get('explore/{spot}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
    Route::post('explore/{spot}/reviews', [ReviewController::class, 'store'])->name('reviews.store');

    // Slot monitoring
    Route::post('slots/toggle', [SlotMonitorController::class, 'toggle'])->name('slots.toggle');

    // User places CRUD
    Route::post('user-places', [UserPlaceController::class, 'store'])->name('user-places.store');
    Route::put('user-places/{userPlace}', [UserPlaceController::class, 'update'])->name('user-places.update');
    Route::delete('user-places/{userPlace}', [UserPlaceController::class, 'destroy'])->name('user-places.destroy');

    // Placeholder pages
    Route::inertia('language-exchange', 'language-exchange')->name('language-exchange');
    Route::inertia('chat', 'chat')->name('chat');
    Route::inertia('neighborhoods', 'neighborhoods')->name('neighborhoods');
    Route::get('services', [ServicesController::class, 'index'])->name('services');
    Route::get('bureaucracy', [BureaucracyController::class, 'index'])->name('bureaucracy');
    Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
    Route::inertia('just-arrived', 'just-arrived')->name('just-arrived');
    Route::get('profile', ProfilePageController::class)->name('profile');
});

require __DIR__.'/settings.php';
