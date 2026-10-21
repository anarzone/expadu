<?php

/*
| Paperwork (session 2). Loaded inside the app's auth + verified +
| app-writes group in routes/web.php.
*/

use App\Http\Controllers\Bureaucracy\V2\AccountPlanController;
use App\Http\Controllers\Bureaucracy\V2\DelegationController;
use App\Http\Controllers\Bureaucracy\V2\DependentAuthorityController;
use App\Http\Controllers\Bureaucracy\V2\EvidenceController;
use App\Http\Controllers\Bureaucracy\V2\EvidenceSharingController;
use App\Http\Controllers\Bureaucracy\V2\FactConflictController;
use App\Http\Controllers\Bureaucracy\V2\FactExtractionController;
use App\Http\Controllers\Bureaucracy\V2\FactSchemaController;
use App\Http\Controllers\Bureaucracy\V2\OnboardingDraftController;
use App\Http\Controllers\Bureaucracy\V2\PeopleController;
use App\Http\Controllers\Bureaucracy\V2\PersonDataController;
use App\Http\Controllers\Bureaucracy\V2\PersonFactController;
use App\Http\Controllers\Bureaucracy\V2\PersonPlanController;
use App\Http\Controllers\Bureaucracy\V2\ProcessController;
use App\Http\Controllers\Bureaucracy\V2\QuestionSessionController;
use App\Http\Controllers\Bureaucracy\V2\RelationshipController;
use App\Http\Controllers\Bureaucracy\V2\RequirementUseController;
use App\Http\Controllers\Bureaucracy\V2\ScenarioPreviewController;
use App\Http\Controllers\BureaucracyController;
use App\Http\Controllers\Paperwork\CoverageWaitlistController;
use App\Http\Controllers\QA\PersonaController;
use Illuminate\Support\Facades\Route;

Route::get('bureaucracy', [BureaucracyController::class, 'index'])->name('bureaucracy');
// Outside Cologne: "Tell me when it's covered" joins the city waitlist,
// confirmed by the website's double opt-in (EXP-80).
Route::post('paperwork/waitlist', [CoverageWaitlistController::class, 'store'])->middleware('throttle:6,1')->name('paperwork.waitlist');
// Retired QA writes: explicit 410 for stale clients; use the v2 read-only preview.
Route::post('qa/become/{persona}', [PersonaController::class, 'become'])->name('qa.become');
Route::post('qa/reset-tasks', [PersonaController::class, 'resetTasks'])->name('qa.reset-tasks');

// Paperwork v2: the plan, people and sharing, facts and question sessions.
Route::prefix('bureaucracy/v2')->name('bureaucracy.v2.')->middleware('throttle:bureaucracy-v2')->group(function () {
    Route::get('plan', AccountPlanController::class)->name('plan');
    Route::get('preview/{persona}', ScenarioPreviewController::class)->name('preview');
    Route::get('people', [PeopleController::class, 'index'])->name('people.index');
    Route::get('people/{person}/paperwork', [EvidenceController::class, 'index'])->name('paperwork.index');
    Route::get('evidence/{evidence}/shares', [EvidenceSharingController::class, 'index'])->whereUuid('evidence')->name('evidence.shares.index');
    Route::post('evidence/{evidence}/shares', [EvidenceSharingController::class, 'store'])->whereUuid('evidence')->name('evidence.shares.store');
    Route::delete('evidence/{evidence}/shares/{share}', [EvidenceSharingController::class, 'destroy'])->whereUuid('evidence')->whereNumber('share')->name('evidence.shares.destroy');
    Route::put('people/{person}/evidence/{evidenceId}', [EvidenceController::class, 'update'])->whereUuid('evidenceId')->name('evidence.update');
    Route::post('processes/{process}/requirements/{requirement}/confirm', [RequirementUseController::class, 'store'])->whereNumber('process')->name('requirements.confirm');
    Route::delete('processes/{process}/requirements/{requirement}/confirmation', [RequirementUseController::class, 'destroy'])->whereNumber('process')->name('requirements.withdraw');
    Route::get('processes/{process}', [ProcessController::class, 'show'])->whereNumber('process')->name('processes.show');
    Route::post('processes/{process}/events', [ProcessController::class, 'store'])->whereNumber('process')->name('processes.events');
    Route::post('processes/{process}/review', [ProcessController::class, 'review'])->whereNumber('process')->name('processes.review');
    Route::post('processes/{process}/events/{event}/corrections', [ProcessController::class, 'correct'])->whereNumber(['process', 'event'])->name('processes.correct');
    Route::post('people/self', [PeopleController::class, 'store'])->name('people.self');
    Route::get('people/{person}', [PeopleController::class, 'show'])->name('people.show');
    Route::get('people/{person}/sharing', [DelegationController::class, 'index'])->name('people.sharing');
    Route::get('people/{person}/facts', [PersonFactController::class, 'index'])->name('facts.index');
    Route::get('facts/schema', FactSchemaController::class)->name('facts.schema');
    Route::get('people/{person}/facts/{key}/history', [PersonFactController::class, 'history'])->where('key', '[a-z][a-z0-9_]*')->name('facts.history');
    Route::get('people/{person}/fact-conflicts', [FactConflictController::class, 'index'])->name('fact-conflicts.index');
    Route::post('people/{person}/fact-conflicts/{key}/resolve', [FactConflictController::class, 'resolve'])->name('fact-conflicts.resolve');
    Route::put('people/{person}/facts/{key}', [PersonFactController::class, 'change'])->name('facts.change');
    Route::post('people/{person}/facts/{fact}/corrections', [PersonFactController::class, 'correct'])->whereNumber('fact')->name('facts.correct');
    Route::get('people/{person}/question-preview', [QuestionSessionController::class, 'preview'])->name('questions.preview');
    Route::get('people/{person}/plan', PersonPlanController::class)->name('people.plan');
    Route::post('people/{person}/processes', [ProcessController::class, 'start'])->name('processes.start');
    Route::post('people/{person}/question-sessions', [QuestionSessionController::class, 'store'])->name('question-sessions.store');
    Route::post('question-sessions/{session}/next', [QuestionSessionController::class, 'next'])->name('question-sessions.next');
    Route::post('question-sessions/{session}/answers/{question}', [QuestionSessionController::class, 'answer'])->whereNumber('question')->name('question-sessions.answer');
    Route::post('question-sessions/{session}/defer/{question}', [QuestionSessionController::class, 'defer'])->whereNumber('question')->name('question-sessions.defer');
    Route::post('question-sessions/{session}/resume', [QuestionSessionController::class, 'resume'])->name('question-sessions.resume');
    Route::post('question-sessions/{session}/extract/{question}', [FactExtractionController::class, 'extract'])->whereNumber('question')->middleware('throttle:bureaucracy-extract')->name('questions.extract');
    Route::post('question-sessions/{session}/candidates/{candidate}/confirm', [FactExtractionController::class, 'confirm'])->whereUuid('candidate')->name('questions.confirm-extraction');
    Route::delete('question-sessions/{session}/candidates/{candidate}', [FactExtractionController::class, 'reject'])->whereUuid('candidate')->name('questions.reject-extraction');
    Route::delete('people/{person}/processing', [FactExtractionController::class, 'withdraw'])->name('people.processing.withdraw');
    Route::get('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'show'])->name('onboarding.draft.show');
    Route::put('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'update'])->name('onboarding.draft.update');
    Route::delete('people/{person}/onboarding/draft', [OnboardingDraftController::class, 'destroy'])->name('onboarding.draft.destroy');
    Route::get('people/{person}/onboarding/review', [OnboardingDraftController::class, 'review'])->name('onboarding.review');
    Route::post('people/{person}/onboarding/complete', [OnboardingDraftController::class, 'complete'])->name('onboarding.complete');
    Route::get('people/{person}/relationships', [RelationshipController::class, 'index'])->name('relationships.index');
    Route::post('people/{person}/relationships', [RelationshipController::class, 'store'])->name('relationships.store');
    Route::delete('people/{person}/relationships/{relationship}', [RelationshipController::class, 'destroy'])->whereNumber('relationship')->name('relationships.destroy');
    Route::post('invitations', [DelegationController::class, 'store'])->middleware('throttle:bureaucracy-invitations')->name('invitations.store');
    Route::post('invitations/inspect', [DelegationController::class, 'inspect'])->name('invitations.inspect');
    Route::post('invitations/accept', [DelegationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [DelegationController::class, 'cancel'])->name('invitations.cancel');
    Route::delete('grants/{grant}', [DelegationController::class, 'revoke'])->name('grants.revoke');
    Route::post('dependents', [DependentAuthorityController::class, 'store'])->middleware('throttle:bureaucracy-dependents')->name('dependents.store');
    Route::post('authorities/{authority}/approve', [DependentAuthorityController::class, 'approve'])->middleware('password.confirm')->name('authorities.approve');
    Route::delete('authorities/{authority}', [DependentAuthorityController::class, 'revoke'])->name('authorities.revoke');
    Route::post('people/{person}/export', [PersonDataController::class, 'export'])->middleware('password.confirm')->name('people.export');
    Route::delete('people/{person}', [PersonDataController::class, 'destroy'])->middleware('password.confirm')->name('people.destroy');
});
