<?php

use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\ConfirmedBureaucracyAttributes;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\Alert;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPerson;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Auth\Access\AuthorizationException;

test('only the subject can export and erase adult records and old onboarding cannot resurrect them', function () {
    $subject = User::factory()->onboarded()->create(['profile_attributes' => ['entry_mode' => 'd_visa']]);
    $helper = User::factory()->onboarded()->create();
    $case = app(CaseFactStore::class)->synchronizeConfirmedFacts($subject, ['entry_mode' => 'd_visa'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($subject);
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_facts', 'edit_facts']);
    app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_facts', 'edit_facts']);
    expect(fn () => app(PersonDataLifecycle::class)->export($helper, $person))->toThrow(AuthorizationException::class);
    expect(fn () => app(PersonDataLifecycle::class)->erase($helper, $person))->toThrow(AuthorizationException::class);
    expect(app(PersonDataLifecycle::class)->export($subject, $person)['facts'][0]['value'])->toBe('d_visa');
    app(PersonDataLifecycle::class)->erase($subject, $person);
    app(LegacyFactBootstrapper::class)->bootstrap($subject->fresh());
    expect(BureaucracyCaseFact::query()->where('case_id', $case->id)->count())->toBe(0)
        ->and(app(ConfirmedBureaucracyAttributes::class)->forUser($subject))->toBe([])
        ->and($person->fresh()->record_status)->toBe('erased');
});

test('deleting a helper account does not cascade into a linked adult dossier', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $case = app(CaseFactStore::class)->synchronizeConfirmedFacts($subject, ['german_level' => 'b1'], 'onboarding');
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_plan']);
    $person = app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan']);
    $helper->delete();
    expect($person->fresh()->account_user_id)->toBe($subject->id)
        ->and($case->fresh()->facts()->sole()->value)->toBe('b1')
        ->and(BureaucracyPerson::query()->whereNull('account_user_id')->where('kind', 'adult')->sole()->record_status)->toBe('erased');
});

test('deleting the subject also removes their legacy task notes and derived alert text', function () {
    $subject = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($subject, ['entry_mode' => 'd_visa'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($subject);
    $task = UserTask::factory()->for($subject)->create(['notes' => 'Synthetic private appointment note']);
    $alert = Alert::factory()->for($subject)->create(['category' => 'bureaucracy', 'body' => 'Synthetic private residency detail']);
    app(PersonDataLifecycle::class)->erase($subject, $person);
    expect($task->fresh())->toBeNull()->and($alert->fresh())->toBeNull();
});

test('an erased dossier does not recreate questions or materialise universal tasks on a later read', function () {
    $subject = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($subject, ['entry_mode' => 'd_visa'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($subject);
    Task::factory()->approvedFixture()->create(['coverage_scope' => 'universal', 'applies_if' => []]);
    app(PersonDataLifecycle::class)->erase($subject, $person);
    app(PathGenerator::class)->ensure($subject->fresh());
    app(CurrentCasePlan::class)->for($subject->fresh());
    expect($subject->userTasks()->count())->toBe(0)
        ->and($person->dossier->questions()->count())->toBe(0)
        ->and($person->dossier->planSnapshots()->count())->toBe(0);
});
