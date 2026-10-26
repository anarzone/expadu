<?php

use App\Http\Controllers\Settings\AccountExportController;
use App\Http\Controllers\Settings\NotificationController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\StepFreeNeedController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:app-writes'])->group(function () {
    // Settings (design canvas "Settings and account", 22 Oct 2026): one page
    // of panels. It reads the shared auth and userSettings props and writes
    // through the endpoints below, /user-settings and the email-code route.
    Route::inertia('settings', 'settings')->name('settings');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

    Route::get('settings/notifications', [NotificationController::class, 'edit'])->name('notifications.edit');

    // The step-free need: one writer (SetStepFreeNeed), which Composer's set_needs uses too.
    Route::put('settings/needs/step-free', StepFreeNeedController::class)->name('needs.step-free.update');

    // Transit reads the shared userSettings / auth.user props and writes through
    // the existing /user-settings and /settings/profile endpoints, so it needs
    // no dedicated controller. Location sharing folded in here (it's a transit
    // setting); the old /settings/privacy path redirects for stray bookmarks.
    Route::redirect('settings/privacy', '/settings/transit');
    Route::inertia('settings/transit', 'settings/transit')->name('transit.edit');
    // Whether Composer asks before it changes something (Ask first), from the shared
    // userSettings prop, written through /user-settings like Transit's.
    Route::inertia('settings/composer', 'settings/composer')->name('composer-settings.edit');
});

Route::middleware(['auth', 'verified', 'throttle:app-writes'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Download your data: confirmed like deleting the account; the file is
    // only the asker's and lasts 24 hours (AccountExportController).
    Route::get('settings/export', [AccountExportController::class, 'show'])->name('account-export.show');
    Route::post('settings/export', [AccountExportController::class, 'store'])->middleware('throttle:6,1')->name('account-export.store');
    Route::get('settings/export/{export}', [AccountExportController::class, 'download'])->name('account-export.download');

});
