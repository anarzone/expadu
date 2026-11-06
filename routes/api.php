<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\SignInController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TravelController;
use Illuminate\Support\Facades\Route;

/*
 * The iPhone app's API. Bearer tokens only (config/sanctum.php): no cookie opens these routes.
 * The older /api/* routes in web.php belong to the web app's session.
 */
Route::prefix('v1')->middleware('throttle:app-api')->group(function () {
    Route::post('auth/token', [SignInController::class, 'token'])->middleware('throttle:login');
    Route::post('auth/two-factor', [SignInController::class, 'twoFactor'])->middleware('throttle:app-two-factor');
    Route::post('auth/exchange', [SignInController::class, 'exchange'])->middleware('throttle:login');
    Route::post('auth/apple', [SignInController::class, 'apple'])->middleware('throttle:login');
    Route::post('auth/register', [SignInController::class, 'register'])->middleware('throttle:fortify-forms');
    Route::post('auth/forgot-password', [SignInController::class, 'forgotPassword'])->middleware('throttle:fortify-forms');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/refresh', [SignInController::class, 'refresh']);
        Route::delete('auth/token', [SignInController::class, 'signOut']);

        Route::get('me', [AccountController::class, 'show']);
        Route::post('me/email-verification', [AccountController::class, 'resendVerification'])->middleware('throttle:fortify-forms');
        Route::post('me/deletion-code', [AccountController::class, 'sendDeletionCode'])->middleware(['verified', 'throttle:email-confirmation']);
        Route::post('me/delete', [AccountController::class, 'destroy'])->middleware('verified');

        Route::get('today', [TodayController::class, 'show'])->middleware('verified');
        Route::get('travel/nearby', [TravelController::class, 'nearby'])->middleware('verified');
    });
});
