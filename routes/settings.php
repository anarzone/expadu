<?php

use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:app-writes'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');

    // Transit reads the shared userSettings / auth.user props and writes through
    // the existing /user-settings and /settings/profile endpoints, so it needs
    // no dedicated controller. Location sharing folded in here (it's a transit
    // setting); the old /settings/privacy path redirects for stray bookmarks.
    Route::redirect('settings/privacy', '/settings/transit');
    Route::inertia('settings/transit', 'settings/transit')->name('transit.edit');
});

Route::middleware(['auth', 'verified', 'throttle:app-writes'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');
});
