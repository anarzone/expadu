<?php

/*
| Composer (session 2). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php.
*/

use App\Http\Controllers\ComposerController;
use App\Http\Controllers\ComposerWorkspaceController;
use App\Http\Controllers\ProcessingNoticeController;
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
// What each external processing purpose sends and to whom; withdrawing ends it.
Route::get('privacy/processing/{purpose}', ProcessingNoticeController::class)
    ->middleware('throttle:privacy-processing')->name('privacy.processing.show');
Route::delete('privacy/processing/{purpose}', [ProcessingNoticeController::class, 'withdraw'])
    ->middleware('throttle:privacy-processing')->name('privacy.processing.withdraw');
Route::post('composer/compose', [ComposerController::class, 'compose'])
    ->middleware('throttle:composer-compose')
    ->name('composer.compose');
Route::post('composer/swap', [ComposerController::class, 'swap'])->name('composer.swap');
// Pin / un-pin the composed plan to the Today screen.
Route::post('composer/save', [ComposerController::class, 'save'])->name('composer.save');
// Keep the composed plan as it is (a personalisation signal, with the opt-in).
Route::post('composer/keep', [ComposerController::class, 'keep'])->name('composer.keep');
Route::delete('composer/today', [ComposerController::class, 'clearToday'])->name('composer.today.clear');
