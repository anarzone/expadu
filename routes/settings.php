<?php

use App\Http\Controllers\Settings\AccountExportController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SettingsController;
use App\Http\Controllers\Settings\StepFreeNeedController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'throttle:app-writes'])->group(function () {
    // Settings (owner, 26 Oct 2026: direction A): one page, every section
    // in its menu, each at its own address (SettingsController). It reads
    // the shared auth and userSettings props and writes through the
    // endpoints below, /user-settings, /notification-preferences and the
    // email-code route.
    Route::get('settings', SettingsController::class)->name('settings');
    // Your data and Delete account ask for a verified email, as their endpoints do (SettingsController).
    Route::get('settings/{section}', SettingsController::class)
        ->whereIn('section', SettingsController::SECTIONS)
        ->name('settings.section');

    // Old addresses: the profile and security are Your account now; location
    // sharing is under Transit & tickets.
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::redirect('settings/security', '/settings/account');
    Route::redirect('settings/privacy', '/settings/transit');

    // The step-free need: one writer (SetStepFreeNeed), which Composer's set_needs uses too.
    Route::put('settings/needs/step-free', StepFreeNeedController::class)->name('needs.step-free.update');
});

Route::middleware(['auth', 'verified', 'throttle:app-writes'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // Download your data: confirmed like deleting the account; the file is
    // only the asker's and lasts 24 hours (AccountExportController).
    Route::get('settings/export', [AccountExportController::class, 'show'])->name('account-export.show');
    Route::post('settings/export', [AccountExportController::class, 'store'])->middleware('throttle:6,1')->name('account-export.store');
    Route::get('settings/export/{export}', [AccountExportController::class, 'download'])->name('account-export.download');

});
