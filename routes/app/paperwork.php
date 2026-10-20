<?php

/*
| Paperwork (session 2). Loaded inside the app's auth + verified +
| app-writes group in routes/web.php.
*/

use App\Http\Controllers\Bureaucracy\AiConsentController;
use App\Http\Controllers\Bureaucracy\CaseMessageController;
use App\Http\Controllers\BureaucracyCaseConflictController;
use App\Http\Controllers\BureaucracyCaseQuestionController;
use App\Http\Controllers\BureaucracyCaseTaskController;
use App\Http\Controllers\BureaucracyCaseTaskDocumentsController;
use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\BureaucracyDemoController;
use App\Http\Controllers\ProfileAttributeController;
use App\Http\Controllers\QA\PersonaController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserTaskController;
use Illuminate\Support\Facades\Route;

Route::get('bureaucracy', [BureaucracyController::class, 'index'])->name('bureaucracy');
Route::put('bureaucracy/case/ai-consent', AiConsentController::class)
    ->name('bureaucracy.case.ai-consent.update');
Route::post('bureaucracy/case/messages', CaseMessageController::class)
    ->middleware('throttle:bureaucracy-ai-burst')
    ->name('bureaucracy.case.messages.store');
Route::post('bureaucracy/case/questions/{question}', BureaucracyCaseQuestionController::class)
    ->name('bureaucracy.case-question.answer');
Route::patch('bureaucracy/case/conflicts/{conflict}', BureaucracyCaseConflictController::class)
    ->name('bureaucracy.case-conflict.resolve');
Route::patch('bureaucracy/case/tasks/{task:key}', BureaucracyCaseTaskController::class)
    ->name('bureaucracy.case-task.update');
Route::patch('bureaucracy/case/tasks/{task:key}/documents', BureaucracyCaseTaskDocumentsController::class)
    ->name('bureaucracy.case-task.documents.update');
// Admin/local-only: view the real bureaucracy page as any synthetic persona.
Route::get('bureaucracy/demo', BureaucracyDemoController::class)->name('bureaucracy.demo');
Route::post('bureaucracy/path', [BureaucracyController::class, 'setPath'])->name('bureaucracy.set-path');
Route::post('bureaucracy/settle', [BureaucracyController::class, 'settle'])->name('bureaucracy.settle');
// Admin/local-only: flip the CURRENT account to a persona with real writes.
Route::post('qa/become/{persona}', [PersonaController::class, 'become'])->name('qa.become');
Route::post('qa/reset-tasks', [PersonaController::class, 'resetTasks'])->name('qa.reset-tasks');
Route::post('profile/attributes', [ProfileAttributeController::class, 'store'])->name('profile.attributes');
Route::post('tasks/{task}/toggle', [TaskController::class, 'toggle'])->name('tasks.toggle');
Route::post('tasks/{task}/report-outdated', [TaskController::class, 'reportOutdated'])->name('tasks.report-outdated');
Route::patch('user-tasks/{userTask}', [UserTaskController::class, 'update'])->name('user-tasks.update');
