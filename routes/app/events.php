<?php

/*
| Events (session 4). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\Api\EventBookmarkController;
use App\Http\Controllers\Api\EventLaterDatesController;
use App\Http\Controllers\Api\EventOtherDayController;
use App\Http\Controllers\Api\EventReminderController;
use App\Http\Controllers\Api\EventsController;
use App\Http\Controllers\Api\EventStartingPointController;
use App\Http\Controllers\EventController;
use Illuminate\Support\Facades\Route;

Route::get('api/events', [EventsController::class, 'index'])->name('api.events');
Route::get('api/events/other-day', EventOtherDayController::class)->middleware('throttle:60,1')->name('api.events.other-day');
Route::get('api/events/{event}/later', EventLaterDatesController::class)->middleware('throttle:60,1')->name('api.events.later');
Route::post('api/events/starting-points', EventStartingPointController::class)->middleware('throttle:60,1')->name('api.events.starting-points');
Route::get('api/event-bookmarks', [EventBookmarkController::class, 'index'])->name('api.event-bookmarks.index');
Route::post('api/event-bookmarks', [EventBookmarkController::class, 'store'])->middleware('throttle:60,1')->name('api.event-bookmarks.store');
Route::get('api/reminders', [EventReminderController::class, 'index'])->name('api.reminders.index');
Route::post('api/reminders', [EventReminderController::class, 'store'])->name('api.reminders.store');
Route::delete('api/reminders/{event}', [EventReminderController::class, 'destroy'])->name('api.reminders.destroy');

Route::get('events', [EventController::class, 'index'])->name('events');
Route::get('events/saved', [EventController::class, 'saved'])->name('events.saved');
Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');
Route::post('events/{event}/join', [EventController::class, 'join'])->name('events.join');
Route::delete('events/{event}/join', [EventController::class, 'leave'])->name('events.leave');
