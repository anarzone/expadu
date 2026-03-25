<?php

use App\Http\Controllers\HomeFeedController;
use App\Http\Controllers\OnboardingController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('onboarding', 'onboarding')->name('onboarding');
    Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');

    Route::get('dashboard', HomeFeedController::class)->name('dashboard');

    // Placeholder pages — will be replaced with controllers in later phases
    Route::inertia('explore', 'explore')->name('explore');
    Route::inertia('transit', 'transit')->name('transit');
    Route::inertia('events', 'events')->name('events');
    Route::inertia('language-exchange', 'language-exchange')->name('language-exchange');
    Route::inertia('chat', 'chat')->name('chat');
    Route::inertia('neighborhoods', 'neighborhoods')->name('neighborhoods');
    Route::inertia('services', 'services')->name('services');
    Route::inertia('bureaucracy', 'bureaucracy')->name('bureaucracy');
    Route::inertia('just-arrived', 'just-arrived')->name('just-arrived');
    Route::inertia('alerts', 'alerts')->name('alerts');
    Route::inertia('profile', 'profile')->name('profile');
});

require __DIR__.'/settings.php';
