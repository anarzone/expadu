<?php

/*
| Composer (session 2). Loaded inside the app's auth + verified + app-writes
| group in routes/web.php. Its JSON routes are one list with the iPhone app's
| (routes/app/composer-shared.php); the page, the hand-off to it and compose are web-only.
*/

use App\Http\Controllers\ComposerController;
use Illuminate\Support\Facades\Route;

Route::get('composer', [ComposerController::class, 'page'])->name('composer');
// A page's request handed to Composer by POST, so its words stay out of URLs.
Route::post('composer/entry', [ComposerController::class, 'entry'])
    ->middleware('throttle:composer-ask')
    ->name('composer.entry.store');
Route::post('composer/compose', [ComposerController::class, 'compose'])
    ->middleware('throttle:composer-compose')
    ->name('composer.compose');

(require __DIR__.'/composer-shared.php')(app: false);
