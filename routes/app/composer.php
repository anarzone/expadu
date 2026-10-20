<?php

/*
| Composer (session 2). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\ComposerController;
use App\Http\Controllers\ComposerWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('composer', [ComposerController::class, 'page'])->name('composer');
// A page's request handed to Composer by POST, so its words stay out of URLs.
Route::post('composer/entry', [ComposerController::class, 'entry'])
    ->middleware('throttle:composer-parse')
    ->name('composer.entry.store');
Route::get('composer/workspace', [ComposerWorkspaceController::class, 'show'])->name('composer.workspace.show');
Route::post('composer/workspace', [ComposerWorkspaceController::class, 'update'])->name('composer.workspace.update');
Route::get('composer/event', [ComposerWorkspaceController::class, 'event'])->name('composer.event');
Route::get('composer/guidance', [ComposerWorkspaceController::class, 'guidance'])->name('composer.guidance');
Route::get('composer/catalogue', [ComposerWorkspaceController::class, 'catalogue'])->name('composer.catalogue');
Route::post('composer/parse', [ComposerController::class, 'parse'])
    ->middleware('throttle:composer-parse')
    ->name('composer.parse');
Route::post('composer/compose', [ComposerController::class, 'compose'])
    ->middleware('throttle:composer-compose')
    ->name('composer.compose');
Route::post('composer/swap', [ComposerController::class, 'swap'])->name('composer.swap');
// Pin / un-pin the composed plan to the Today screen.
Route::post('composer/save', [ComposerController::class, 'save'])->name('composer.save');
Route::delete('composer/today', [ComposerController::class, 'clearToday'])->name('composer.today.clear');
