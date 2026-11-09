<?php

/*
| Composer's JSON routes, one list for the web (routes/app/composer.php, session) and the iPhone
| app (routes/api.php, its token). The app's routes use subclasses of the same controllers, so the
| web's generated route helpers keep one URL per action. The page, the hand-off to it and compose
| (called in-process by the assistant) stay web-only.
*/

use App\Http\Controllers\Api\V1\Composer\AppComposerController;
use App\Http\Controllers\Api\V1\Composer\AppComposerPaperworkPermissionController;
use App\Http\Controllers\Api\V1\Composer\AppComposerWorkspaceController;
use App\Http\Controllers\Api\V1\Composer\AppProcessingNoticeController;
use App\Http\Controllers\ComposerController;
use App\Http\Controllers\ComposerPaperworkPermissionController;
use App\Http\Controllers\ComposerWorkspaceController;
use App\Http\Controllers\ProcessingNoticeController;
use Illuminate\Support\Facades\Route;

return function (bool $app): void {
    $composer = $app ? AppComposerController::class : ComposerController::class;
    $workspace = $app ? AppComposerWorkspaceController::class : ComposerWorkspaceController::class;
    $notice = $app ? AppProcessingNoticeController::class : ProcessingNoticeController::class;
    $permission = $app ? AppComposerPaperworkPermissionController::class : ComposerPaperworkPermissionController::class;

    Route::get('composer/workspace', [$workspace, 'show'])->name('composer.workspace.show');
    // Your day, the Paperwork glance, tonight and saved starting points, for the panel on any page.
    Route::get('composer/day', [$workspace, 'day'])->name('composer.day');
    Route::post('composer/workspace', [$workspace, 'update'])->name('composer.workspace.update');
    // One of the person's own conversations, renamed or deleted for good (the page asks first).
    Route::patch('composer/conversations/{conversation}', [$workspace, 'renameConversation'])
        ->where('conversation', '[A-Za-z0-9-]{1,64}')->name('composer.conversations.rename');
    Route::delete('composer/conversations/{conversation}', [$workspace, 'deleteConversation'])
        ->where('conversation', '[A-Za-z0-9-]{1,64}')->name('composer.conversations.destroy');
    Route::get('composer/event', [$workspace, 'event'])->name('composer.event');
    Route::get('composer/guidance', [$workspace, 'guidance'])->name('composer.guidance');
    Route::get('composer/catalogue', [$workspace, 'catalogue'])->name('composer.catalogue');
    // One message, answered by Composer's assistant (its last message in the conversation).
    Route::post('composer/ask', [$composer, 'ask'])
        ->middleware('throttle:composer-ask')
        ->name('composer.ask');
    // A change Composer proposed, applied; one it made, undone (24 hours); a change made on a card (ComposerActions).
    Route::post('composer/apply', [$composer, 'applyAction'])->middleware('throttle:composer-ask')->name('composer.apply');
    Route::post('composer/undo', [$composer, 'undoAction'])->middleware('throttle:composer-ask')->name('composer.undo');
    Route::post('composer/act', [$composer, 'act'])->middleware('throttle:composer-ask')->name('composer.act');
    // How far the assistant has got with a request (its permit's request id), polled while it works.
    Route::get('composer/progress/{requestId}', [$composer, 'progress'])
        ->whereUuid('requestId')->middleware('throttle:composer-progress')->name('composer.progress');
    // What each external processing purpose sends and to whom; withdrawing ends it.
    Route::get('privacy/processing/{purpose}', $notice)
        ->middleware('throttle:privacy-processing')->name('privacy.processing.show');
    Route::delete('privacy/processing/{purpose}', [$notice, 'withdraw'])
        ->middleware('throttle:privacy-processing')->name('privacy.processing.withdraw');
    // Composer's remembered Allow for their Paperwork record, for Settings › Composer: on with the notice shown, off at once.
    Route::get('composer/paperwork-permission', [$permission, 'show'])
        ->middleware('throttle:privacy-processing')->name('composer.paperwork-permission.show');
    Route::put('composer/paperwork-permission', [$permission, 'update'])
        ->middleware('throttle:privacy-processing')->name('composer.paperwork-permission.update');
    // Keep the composed plan as it is (a personalisation signal, with the opt-in).
    Route::post('composer/keep', [$composer, 'keep'])->name('composer.keep');
};
