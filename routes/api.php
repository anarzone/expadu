<?php

use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\EventsController;
use App\Http\Controllers\Api\V1\JourneyController;
use App\Http\Controllers\Api\V1\SignInController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TodayTripController;
use App\Http\Controllers\Api\V1\TravelController;
use App\Http\Controllers\TodayRankController;
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
        Route::post('day/command', [DayController::class, 'command'])->middleware('verified');
        // The Leave card's trip: start it on a way, end it; keep another way for the plan, and undo that.
        Route::post('today/trip', [TodayTripController::class, 'start'])->middleware(['verified', 'throttle:journey']);
        Route::delete('today/trip', [TodayTripController::class, 'end'])->middleware('verified');
        Route::post('today/way', [TodayTripController::class, 'keep'])->middleware(['verified', 'throttle:journey']);
        Route::delete('today/way/{undo}', [TodayTripController::class, 'undo'])->whereUuid('undo')->middleware('verified');
        // What's on: a day's listings (All events on Today).
        Route::get('events', [EventsController::class, 'day'])->middleware('verified');
        Route::post('events/saved', [EventsController::class, 'save'])->middleware(['verified', 'throttle:60,1']);
        // What only the app sees (impressions, Less like this, Not for me), as the web's /api/signals.
        Route::post('signals', SignalController::class)->middleware(['verified', 'throttle:signals']);
        // Today's own order after its notice changed: agree, or not now for a week.
        Route::put('today-rank', [TodayRankController::class, 'update'])->middleware(['verified', 'throttle:privacy-processing']);
        Route::post('today-rank/later', [TodayRankController::class, 'later'])->middleware('verified');
        Route::get('travel/nearby', [TravelController::class, 'nearby'])->middleware('verified');
        // Getting there (an event's details): the ways, and I'm on my way on one of them.
        Route::post('journey/plan', [JourneyController::class, 'plan'])->middleware(['verified', 'throttle:journey']);
        Route::post('journey/start', [JourneyController::class, 'start'])->middleware(['verified', 'throttle:journey']);
    });
});
