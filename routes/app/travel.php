<?php

/*
| Travel (session 3). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\Api\FavouriteStopController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\JourneySuggestController;
use App\Http\Controllers\Api\NearbyDeparturesController;
use App\Http\Controllers\Api\ReverseGeocodeController;
use App\Http\Controllers\Api\SavedJourneyController;
use App\Http\Controllers\Api\StopSearchController;
use App\Http\Controllers\Api\TakeMeThereController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

// Typeahead/geocoding lookups hit MOTIS/Photon per request, so they carry
// their own per-user budget on top.
Route::get('api/geocode', GeocodeController::class)->middleware('throttle:search')->name('api.geocode');
Route::get('api/reverse-geocode', ReverseGeocodeController::class)->middleware('throttle:search')->name('api.reverse-geocode');
Route::get('api/stops', StopSearchController::class)->middleware('throttle:search')->name('api.stops');
Route::get('api/nearby-departures', NearbyDeparturesController::class)->name('api.nearby-departures');
Route::get('api/journey', TakeMeThereController::class)->middleware('throttle:journey')->name('api.journey');
Route::get('api/journey/suggest', JourneySuggestController::class)->middleware('throttle:search')->name('api.journey.suggest');
Route::get('api/saved-journeys', [SavedJourneyController::class, 'index'])->name('api.saved-journeys.index');
Route::post('api/saved-journeys', [SavedJourneyController::class, 'store'])->name('api.saved-journeys.store');
Route::delete('api/saved-journeys/{savedJourney}', [SavedJourneyController::class, 'destroy'])->whereNumber('savedJourney')->name('api.saved-journeys.destroy');
// Travel's ★ favourite stops, per account.
Route::get('api/travel/favourite-stops', [FavouriteStopController::class, 'index'])->name('api.travel.favourite-stops.index');
Route::post('api/travel/favourite-stops', [FavouriteStopController::class, 'store'])->name('api.travel.favourite-stops.store');
Route::delete('api/travel/favourite-stops', [FavouriteStopController::class, 'destroy'])->name('api.travel.favourite-stops.destroy');
// Live trip session — persists the chosen journey across screens/closes.
Route::post('api/trip/start', [TripController::class, 'start'])->name('api.trip.start');
Route::post('api/trip/end', [TripController::class, 'end'])->name('api.trip.end');

// Live departures (KVB-style board)
Route::get('timetable', TimetableController::class)->name('timetable');
