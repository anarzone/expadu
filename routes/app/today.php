<?php

/*
| Today (session 1). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\HomeFeedController;
use App\Http\Controllers\TileTriageController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', HomeFeedController::class)->name('dashboard');

// Today "Right now" tile triage — done / snooze / dismiss + per-tile undo.
Route::post('api/tiles/triage', [TileTriageController::class, 'store'])->name('api.tiles.triage');
Route::post('api/tiles/triage/undo', [TileTriageController::class, 'undo'])->name('api.tiles.triage.undo');
