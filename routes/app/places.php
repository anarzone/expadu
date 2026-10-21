<?php

/*
| Places and Around here (session 4). Loaded inside the app's auth +
| verified + app-writes group in routes/web.php.
*/

use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\PlaceContextController;
use App\Http\Controllers\Api\PlaceFeedbackController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\SpotSearchController;
use App\Http\Controllers\AroundHereController;
use App\Http\Controllers\ReviewController;
use App\Http\Middleware\KeepQueryPrivate;
use Illuminate\Support\Facades\Route;

Route::get('api/spots', SpotSearchController::class)->middleware('throttle:search')->name('api.spots');
Route::get('api/places', [PlacesController::class, 'index'])->name('api.places');
Route::get('api/places/{spot}/context', PlaceContextController::class)->name('api.places.context');
Route::get('api/places/{spot}/events', [EventsController::class, 'place'])->name('api.places.events');
Route::get('api/places/{spot}', [PlacesController::class, 'show'])->name('api.places.show');
Route::post('api/places/{spot}/feedback', PlaceFeedbackController::class)->name('api.places.feedback');

// Around here: places of a kind near a point (AH1); not in the navigation.
// Its near and time say where someone will be: the session keeps neither.
Route::get('explore', AroundHereController::class)->middleware(KeepQueryPrivate::class)->name('explore');

// Spot reviews — public-facing content, so the tight ugc budget stacks under
// the group-wide app-writes one.
Route::get('explore/{spot}/reviews', [ReviewController::class, 'index'])->name('reviews.index');
Route::post('explore/{spot}/reviews', [ReviewController::class, 'store'])->middleware('throttle:ugc')->name('reviews.store');
