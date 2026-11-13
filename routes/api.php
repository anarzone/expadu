<?php

use App\Http\Controllers\Api\Internal\VrsFeedRelayController;
use App\Http\Controllers\Api\SignalController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AlertsController;
use App\Http\Controllers\Api\V1\Composer\AppComposerController;
use App\Http\Controllers\Api\V1\Composer\AppEventBookmarkController;
use App\Http\Controllers\Api\V1\Composer\AppGeocodeController;
use App\Http\Controllers\Api\V1\Composer\AppPlaceFeedbackController;
use App\Http\Controllers\Api\V1\Composer\AppPlaceWorkAnswerController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\EventsController;
use App\Http\Controllers\Api\V1\JourneyController;
use App\Http\Controllers\Api\V1\PaperworkDocumentsController;
use App\Http\Controllers\Api\V1\SignInController;
use App\Http\Controllers\Api\V1\TodayController;
use App\Http\Controllers\Api\V1\TodayTripController;
use App\Http\Controllers\Api\V1\Travel\AppDeutschlandticketController;
use App\Http\Controllers\Api\V1\Travel\AppFavouriteStopController;
use App\Http\Controllers\Api\V1\Travel\AppJourneyChecksController;
use App\Http\Controllers\Api\V1\Travel\AppJourneyPlanController;
use App\Http\Controllers\Api\V1\Travel\AppJourneySuggestSearchController;
use App\Http\Controllers\Api\V1\Travel\AppLineRouteController;
use App\Http\Controllers\Api\V1\Travel\AppLineStopController;
use App\Http\Controllers\Api\V1\Travel\AppPlannedTripController;
use App\Http\Controllers\Api\V1\Travel\AppStationLinesMapController;
use App\Http\Controllers\Api\V1\Travel\AppStationSearchController;
use App\Http\Controllers\Api\V1\Travel\AppTravelController;
use App\Http\Controllers\Api\V1\Travel\AppTripController;
use App\Http\Controllers\Api\V1\Travel\AppTripRideController;
use App\Http\Controllers\Api\V1\TravelController;
use App\Http\Controllers\TodayRankController;
use Illuminate\Support\Facades\Route;

/*
 * VRS's live feed for our router (MOTIS) on the box's own network; its token or a 404.
 */
Route::get('internal/vrs-gtfs-rt', VrsFeedRelayController::class)->name('internal.vrs-gtfs-rt');

/*
 * The iPhone app's API. Bearer tokens only (config/sanctum.php): no cookie opens these routes.
 * The older /api/* routes in web.php belong to the web app's session.
 */
Route::prefix('v1')->middleware('throttle:app-api')->group(function () {
    Route::post('auth/token', [SignInController::class, 'token'])->middleware('throttle:login');
    Route::post('auth/two-factor', [SignInController::class, 'twoFactor'])->middleware('throttle:app-two-factor');
    Route::post('auth/exchange', [SignInController::class, 'exchange'])->middleware('throttle:login');
    Route::post('auth/apple', [SignInController::class, 'apple'])->middleware('throttle:login');
    // The sealed stamp the sign-up and reset requests carry back (FormTraps).
    Route::get('auth/form', [SignInController::class, 'form']);
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
        // Travel, as the web's Travel page has it (routes/app/travel.php), with the token: the station board
        // by name or by where they are, the stop picker and ★ stops, a line's route and map, and the places
        // a trip can go to (Home or Work set from Plan a trip).
        Route::middleware('verified')->prefix('travel')->group(function () {
            Route::get('station', [AppTravelController::class, 'station']);
            Route::get('places', [AppTravelController::class, 'places'])->middleware('throttle:journey');
            Route::put('places/{category}', [AppTravelController::class, 'setPlace'])->whereIn('category', ['home', 'work']);
            Route::get('stations', AppStationSearchController::class)->middleware('throttle:search');
            Route::get('favourite-stops', [AppFavouriteStopController::class, 'index']);
            Route::post('favourite-stops', [AppFavouriteStopController::class, 'store']);
            Route::delete('favourite-stops', [AppFavouriteStopController::class, 'destroy']);
            Route::get('lines-map', AppStationLinesMapController::class);
            Route::get('line-route', AppLineRouteController::class);
            Route::get('line-stop', AppLineStopController::class)->middleware('throttle:search');
            Route::put('deutschlandticket', AppDeutschlandticketController::class);
            // Plan a trip: suggestions as they type (in the body, never the URL), the ways, and their boarding stops checked again.
            Route::post('suggest', AppJourneySuggestSearchController::class)->middleware('throttle:search');
            Route::post('plan', AppJourneyPlanController::class)->middleware('throttle:journey');
            Route::post('checks', AppJourneyChecksController::class)->middleware('throttle:search');
            // Add to plan, its reminder, and taking it out again.
            Route::post('planned-trips', [AppPlannedTripController::class, 'store']);
            Route::patch('planned-trips/{plannedTrip}', [AppPlannedTripController::class, 'update'])->whereNumber('plannedTrip');
            Route::delete('planned-trips/{plannedTrip}', [AppPlannedTripController::class, 'destroy'])->whereNumber('plannedTrip');
            // I'm on my way on a way from Plan a trip, and stopping it.
            Route::post('trip', [AppTripController::class, 'start'])->middleware('throttle:journey');
            Route::delete('trip', [AppTripController::class, 'end']);
            // The tram they're really on (trip sync): which run, switching to it, and Undo.
            Route::post('trip/ride/check', [AppTripRideController::class, 'check'])->middleware('throttle:journey');
            Route::post('trip/ride', [AppTripRideController::class, 'switch'])->middleware('throttle:journey');
            Route::post('trip/ride/undo', [AppTripRideController::class, 'undo'])->middleware('throttle:journey');
        });
        // Getting there (an event's details): the ways, and I'm on my way on one of them.
        Route::post('journey/plan', [JourneyController::class, 'plan'])->middleware(['verified', 'throttle:journey']);
        Route::post('journey/start', [JourneyController::class, 'start'])->middleware(['verified', 'throttle:journey']);

        // Paperwork: the web's own v2 routes and rules, with the token (routes/app/paperwork-v2.php).
        Route::prefix('paperwork/v2')->name('api.paperwork.v2.')->middleware(['verified', 'throttle:bureaucracy-v2'])
            ->group(fn () => (require __DIR__.'/app/paperwork-v2.php')(app: true));
        // Composer: the web's own routes and rules, with the token (routes/app/composer-shared.php).
        Route::middleware('verified')->name('api.')->group(fn () => (require __DIR__.'/app/composer-shared.php')(app: true));
        // Composer's Saved and Starting point: their saved events, a place saved or unsaved (or Not this),
        // an address search, and their own places to start from.
        Route::get('events/saved', [AppEventBookmarkController::class, 'index'])->middleware('verified');
        Route::post('places/{spot}/feedback', AppPlaceFeedbackController::class)->middleware(['verified', 'throttle:60,1']);
        // A place's sheet: where they stand with it, Been there, and "Good for laptop work?".
        Route::get('places/{spot}/feedback', [AppPlaceFeedbackController::class, 'standing'])->middleware('verified');
        Route::put('places/{spot}/been', [AppPlaceFeedbackController::class, 'been'])->middleware(['verified', 'throttle:60,1']);
        Route::delete('places/{spot}/been', [AppPlaceFeedbackController::class, 'forgetBeen'])->middleware(['verified', 'throttle:60,1']);
        Route::get('places/{spot}/work-answer', [AppPlaceWorkAnswerController::class, 'show'])->middleware('verified');
        Route::put('places/{spot}/work-answer', [AppPlaceWorkAnswerController::class, 'update'])->middleware(['verified', 'throttle:60,1']);
        Route::get('geocode', AppGeocodeController::class)->middleware(['verified', 'throttle:search']);
        Route::get('composer/places', [AppComposerController::class, 'places'])->middleware('verified');
        // The bell's sheet: their alerts, and one read.
        Route::get('alerts', [AlertsController::class, 'index'])->middleware('verified');
        Route::post('alerts/{alert}/read', [AlertsController::class, 'read'])->whereNumber('alert')->middleware('verified');
        // What to bring to a Paperwork appointment (Today's `bring.process`), and ticking a document ready.
        Route::get('paperwork/{process}/documents', [PaperworkDocumentsController::class, 'index'])->where('process', '[a-f0-9]{64}')->middleware('verified');
        Route::post('paperwork/{process}/documents/{document}', [PaperworkDocumentsController::class, 'update'])
            ->where(['process' => '[a-f0-9]{64}', 'document' => '[a-z0-9][a-zA-Z0-9._-]{0,199}'])->middleware(['verified', 'throttle:bureaucracy-v2']);
    });
});
