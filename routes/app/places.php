<?php

/*
| Places and Around here (session 4). Loaded inside the app's auth +
| verified + app-writes group in routes/web.php.
*/

use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\PlaceContextController;
use App\Http\Controllers\Api\PlaceFeedbackController;
use App\Http\Controllers\Api\PlacesController;
use App\Http\Controllers\Api\PlaceWorkAnswerController;
use App\Http\Controllers\Api\SpotSearchController;
use App\Http\Controllers\AroundHereController;
use App\Http\Middleware\KeepQueryPrivate;
use Illuminate\Support\Facades\Route;

Route::get('api/spots', SpotSearchController::class)->middleware('throttle:search')->name('api.spots');
Route::get('api/places', [PlacesController::class, 'index'])->name('api.places');
Route::get('api/places/{spot}/context', PlaceContextController::class)->name('api.places.context');
Route::get('api/places/{spot}/events', [EventsController::class, 'place'])->name('api.places.events');
// Their own laptop-work answers; before api/places/{spot} so it isn't read as a place id.
Route::get('api/places/work-answers', [PlaceWorkAnswerController::class, 'mine'])->name('api.places.work-answers');
Route::get('api/places/{spot}', [PlacesController::class, 'show'])->name('api.places.show');
Route::post('api/places/{spot}/feedback', PlaceFeedbackController::class)->name('api.places.feedback');

// "Good for laptop work?" (people's answers; Been there or Saved only).
Route::get('api/places/{spot}/work-answer', [PlaceWorkAnswerController::class, 'show'])->name('api.places.work-answer.show');
Route::put('api/places/{spot}/work-answer', [PlaceWorkAnswerController::class, 'update'])->middleware('throttle:place-answers')->name('api.places.work-answer.update');
Route::delete('api/places/{spot}/work-answer', [PlaceWorkAnswerController::class, 'destroy'])->middleware('throttle:place-answers')->name('api.places.work-answer.destroy');

// Around here: places of a kind near a point (AH1); not in the navigation.
// Its near and time say where someone will be: the session keeps neither.
Route::get('explore', AroundHereController::class)->middleware(KeepQueryPrivate::class)->name('explore');
