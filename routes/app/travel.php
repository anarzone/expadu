<?php

/*
| Travel (session 3). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\Api\DayController;
use App\Http\Controllers\Api\DeutschlandticketController;
use App\Http\Controllers\Api\FavouriteStopController;
use App\Http\Controllers\Api\GeocodeController;
use App\Http\Controllers\Api\GeocodeSearchController;
use App\Http\Controllers\Api\JourneyChecksController;
use App\Http\Controllers\Api\JourneyPlanController;
use App\Http\Controllers\Api\JourneySuggestController;
use App\Http\Controllers\Api\JourneySuggestSearchController;
use App\Http\Controllers\Api\LineRouteController;
use App\Http\Controllers\Api\LineStopController;
use App\Http\Controllers\Api\NearbyDeparturesController;
use App\Http\Controllers\Api\PlannedTripController;
use App\Http\Controllers\Api\ReverseGeocodeController;
use App\Http\Controllers\Api\SavedJourneyController;
use App\Http\Controllers\Api\StationLinesMapController;
use App\Http\Controllers\Api\StationSearchController;
use App\Http\Controllers\Api\StopSearchController;
use App\Http\Controllers\Api\TakeMeThereController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\TimetableController;
use Illuminate\Support\Facades\Route;

// Typeahead/geocoding lookups hit MOTIS/Photon per request, so they carry
// their own per-user budget on top.
Route::get('api/geocode', GeocodeController::class)->middleware('throttle:search')->name('api.geocode');
// What a person types (a home address, a friend's street) goes in the body,
// never in a URL or an access log; the GET forms go once nothing calls them.
Route::post('api/geocode/search', GeocodeSearchController::class)->middleware('throttle:search')->name('api.geocode.search');
Route::get('api/reverse-geocode', ReverseGeocodeController::class)->middleware('throttle:search')->name('api.reverse-geocode');
Route::get('api/stops', StopSearchController::class)->middleware('throttle:search')->name('api.stops');
Route::get('api/nearby-departures', NearbyDeparturesController::class)->name('api.nearby-departures');
Route::get('api/journey', TakeMeThereController::class)->middleware('throttle:journey')->name('api.journey');
Route::post('api/journey/plan', JourneyPlanController::class)->middleware('throttle:journey')->name('api.journey.plan');
Route::post('api/journey/checks', JourneyChecksController::class)->middleware('throttle:search')->name('api.journey.checks');
Route::put('api/travel/deutschlandticket', DeutschlandticketController::class)->name('api.travel.deutschlandticket');
Route::get('api/journey/suggest', JourneySuggestController::class)->middleware('throttle:search')->name('api.journey.suggest');
Route::post('api/journey/suggest/search', JourneySuggestSearchController::class)->middleware('throttle:search')->name('api.journey.suggest.search');
Route::get('api/saved-journeys', [SavedJourneyController::class, 'index'])->name('api.saved-journeys.index');
Route::post('api/saved-journeys', [SavedJourneyController::class, 'store'])->name('api.saved-journeys.store');
Route::delete('api/saved-journeys/{savedJourney}', [SavedJourneyController::class, 'destroy'])->whereNumber('savedJourney')->name('api.saved-journeys.destroy');
// Nearby's stop picker: KVB stations by name.
Route::get('api/travel/stations', StationSearchController::class)->middleware('throttle:search')->name('api.travel.stations');
// Nearby's Map: the lines through a station and their next stops.
Route::get('api/travel/lines-map', StationLinesMapController::class)->name('api.travel.lines-map');
// The map's picked line: its whole route, and each stop's runs as it comes
// into view (one board read per stop, so the search budget).
Route::get('api/travel/line-route', LineRouteController::class)->name('api.travel.line-route');
Route::get('api/travel/line-stop', LineStopController::class)->middleware('throttle:search')->name('api.travel.line-stop');
// Travel's ★ favourite stops, per account.
Route::get('api/travel/favourite-stops', [FavouriteStopController::class, 'index'])->name('api.travel.favourite-stops.index');
Route::post('api/travel/favourite-stops', [FavouriteStopController::class, 'store'])->name('api.travel.favourite-stops.store');
Route::delete('api/travel/favourite-stops', [FavouriteStopController::class, 'destroy'])->name('api.travel.favourite-stops.destroy');
// Plan a trip's "Add to plan": the way picked for a plan on your day.
Route::post('api/travel/planned-trips', [PlannedTripController::class, 'store'])->name('api.travel.planned-trips.store');
Route::patch('api/travel/planned-trips/{plannedTrip}', [PlannedTripController::class, 'update'])->whereNumber('plannedTrip')->name('api.travel.planned-trips.update');
Route::delete('api/travel/planned-trips/{plannedTrip}', [PlannedTripController::class, 'destroy'])->whereNumber('plannedTrip')->name('api.travel.planned-trips.destroy');
// The signed-in person's day (DayProjection); plans the next trip, so it
// shares the journey planner's rate limit.
Route::get('api/day', DayController::class)->middleware('throttle:journey')->name('api.day');
// Live trip session — persists the chosen journey across screens/closes.
Route::post('api/trip/start', [TripController::class, 'start'])->name('api.trip.start');
Route::post('api/trip/end', [TripController::class, 'end'])->name('api.trip.end');

// Live departures (KVB-style board)
Route::get('timetable', TimetableController::class)->name('timetable');
