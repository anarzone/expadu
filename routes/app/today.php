<?php

/*
| Today (session 1). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\Api\SavedController;
use App\Http\Controllers\HomeFeedController;
use Illuminate\Support\Facades\Route;

Route::get('today', HomeFeedController::class)->name('today');
// Everything saved for later, places and events together (call #4).
Route::get('api/saved', SavedController::class)->name('api.saved');
