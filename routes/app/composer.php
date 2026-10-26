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
    ->middleware('throttle:composer-ask')
    ->name('composer.entry.store');
Route::get('composer/workspace', [ComposerWorkspaceController::class, 'show'])->name('composer.workspace.show');
// Your day, the Paperwork glance, tonight and saved starting points, for the panel on any page.
Route::get('composer/day', [ComposerWorkspaceController::class, 'day'])->name('composer.day');
Route::post('composer/workspace', [ComposerWorkspaceController::class, 'update'])->name('composer.workspace.update');
// One of the person's own conversations, renamed or deleted for good (the page asks first).
Route::patch('composer/conversations/{conversation}', [ComposerWorkspaceController::class, 'renameConversation'])
    ->where('conversation', '[A-Za-z0-9-]{1,64}')->name('composer.conversations.rename');
Route::delete('composer/conversations/{conversation}', [ComposerWorkspaceController::class, 'deleteConversation'])
    ->where('conversation', '[A-Za-z0-9-]{1,64}')->name('composer.conversations.destroy');
Route::get('composer/event', [ComposerWorkspaceController::class, 'event'])->name('composer.event');
Route::get('composer/guidance', [ComposerWorkspaceController::class, 'guidance'])->name('composer.guidance');
Route::get('composer/catalogue', [ComposerWorkspaceController::class, 'catalogue'])->name('composer.catalogue');
// One message, answered by Composer's assistant (its last message in the conversation).
Route::post('composer/ask', [ComposerController::class, 'ask'])
    ->middleware('throttle:composer-ask')
    ->name('composer.ask');
// A change Composer proposed, applied; one it made, undone (24 hours); a change made on a card (ComposerActions).
Route::post('composer/apply', [ComposerController::class, 'applyAction'])->middleware('throttle:composer-ask')->name('composer.apply');
Route::post('composer/undo', [ComposerController::class, 'undoAction'])->middleware('throttle:composer-ask')->name('composer.undo');
Route::post('composer/act', [ComposerController::class, 'act'])->middleware('throttle:composer-ask')->name('composer.act');
// How far the assistant has got with a request (its permit's request id), polled while it works.
Route::get('composer/progress/{requestId}', [ComposerController::class, 'progress'])
    ->whereUuid('requestId')->middleware('throttle:composer-progress')->name('composer.progress');
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
