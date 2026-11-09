<?php

/*
| Paperwork (session 2). Loaded inside the app's auth + verified +
| app-writes group in routes/web.php.
*/

use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\Paperwork\CoverageWaitlistController;
use App\Http\Controllers\QA\PersonaController;
use Illuminate\Support\Facades\Route;

// Paperwork lives at /paperwork, its API at /paperwork/v2 (owner, 27 Oct 2026: the old
// /bureaucracy addresses are gone, not redirected; stored links were rewritten).
Route::get('paperwork', [BureaucracyController::class, 'index'])->name('paperwork');
// Outside Cologne: "Tell me when it's covered" joins the city waitlist,
// confirmed by the website's double opt-in (EXP-80).
Route::post('paperwork/waitlist', [CoverageWaitlistController::class, 'store'])->middleware('throttle:6,1')->name('paperwork.waitlist');
// Retired QA writes: explicit 410 for stale clients; use the v2 read-only preview.
Route::post('qa/become/{persona}', [PersonaController::class, 'become'])->name('qa.become');
Route::post('qa/reset-tasks', [PersonaController::class, 'resetTasks'])->name('qa.reset-tasks');

// Paperwork v2: the plan, people and sharing, facts and question sessions. The same routes serve the
// iPhone at /api/v1/paperwork/v2 (routes/app/paperwork-v2.php).
Route::prefix('paperwork/v2')->name('paperwork.v2.')->middleware('throttle:bureaucracy-v2')
    ->group(fn () => (require __DIR__.'/paperwork-v2.php')(app: false));
