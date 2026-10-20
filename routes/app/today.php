<?php

/*
| Today (session 1). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\HomeFeedController;
use Illuminate\Support\Facades\Route;

Route::get('dashboard', HomeFeedController::class)->name('dashboard');
