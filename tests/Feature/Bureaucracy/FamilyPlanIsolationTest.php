<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\ManageDependents;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('one account keeps one person and its existing encrypted dossier across household invitations', function () {
    $owner = User::factory()->onboarded()->create();
    $spouse = User::factory()->onboarded()->create();
    $case = app(CaseFactStore::class)->synchronizeConfirmedFacts($spouse, ['citizenship_group' => 'non_eu'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($spouse);
    $workspace = app(EnsureAccountHolder::class)->person($owner)->workspace;
    $invite = app(ManageDelegation::class)->invite($owner, $workspace, $spouse->email, ['view_plan', 'view_facts']);
    expect(app(PersonAccess::class)->allows($owner, $person, AccessScope::ViewFacts))->toBeFalse();
    $accepted = app(ManageDelegation::class)->accept($spouse, $invite['token'], ['view_plan']);
    expect($accepted->id)->toBe($person->id)
        ->and(app(EnsureAccountHolder::class)->dossier($spouse)->id)->toBe($case->id)
        ->and(BureaucracyPerson::query()->where('account_user_id', $spouse->id)->count())->toBe(1)
        ->and($person->workspaces()->count())->toBe(2)
        ->and(app(PersonAccess::class)->allows($owner, $person, AccessScope::ViewPlan))->toBeTrue()
        ->and(app(PersonAccess::class)->allows($owner, $person, AccessScope::ViewFacts))->toBeFalse()
        ->and($case->facts()->sole()->value)->toBe('non_eu');
});

test('workspace membership and admin status do not grant adult dossier access', function () {
    $admin = User::factory()->onboarded()->create(['is_admin' => true]);
    $subject = app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create());
    $workspace = app(EnsureAccountHolder::class)->person($admin)->workspace;
    $workspace->people()->attach($subject->id);
    foreach (AccessScope::cases() as $scope) {
        expect(app(PersonAccess::class)->allows($admin, $subject, $scope))->toBeFalse();
    }
    $this->actingAs($admin)->getJson('/bureaucracy/v2/people/'.$subject->id)->assertNotFound();
    $this->getJson('/bureaucracy/v2/people')->assertSuccessful()->assertJsonMissing(['id' => $subject->id]);
});

test('a token does not authorise an unverified or different recipient', function (bool $unverified) {
    $owner = User::factory()->onboarded()->create();
    $recipient = User::factory()->onboarded()->create($unverified ? ['email_verified_at' => null] : []);
    $invite = app(ManageDelegation::class)->invite($owner, app(EnsureAccountHolder::class)->person($owner)->workspace, $recipient->email, ['view_plan']);
    $actor = $unverified ? $recipient : User::factory()->onboarded()->create();
    expect(fn () => app(ManageDelegation::class)->accept($actor, $invite['token'], ['view_plan']))->toThrow(AuthorizationException::class);
})->with([true, false]);

test('invitation recipients cannot expand requested scopes or replay acceptance', function () {
    $owner = User::factory()->onboarded()->create();
    $recipient = User::factory()->onboarded()->create();
    $invite = app(ManageDelegation::class)->invite($owner, app(EnsureAccountHolder::class)->person($owner)->workspace, $recipient->email, ['view_plan']);
    expect(fn () => app(ManageDelegation::class)->accept($recipient, $invite['token'], ['edit_facts']))->toThrow(ValidationException::class);
    app(ManageDelegation::class)->accept($recipient, $invite['token'], ['view_plan']);
    expect(fn () => app(ManageDelegation::class)->accept($recipient, $invite['token'], ['view_plan']))->toThrow(AuthorizationException::class);
    expect($invite['invitation']->getRawOriginal('token_hash'))->not->toContain($invite['token'])
        ->and($invite['invitation']->getRawOriginal('recipient_hash'))->not->toContain($recipient->email);
});

test('a household owner cannot create invitations in someone elses workspace', function () {
    $actor = User::factory()->onboarded()->create();
    $other = app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create());
    expect(fn () => app(ManageDelegation::class)->invite($actor, $other->workspace, 'synthetic@example.test', ['view_plan']))->toThrow(AuthorizationException::class);
});

test('attaching legacy cases preserves revision and rejects a conflicting person association', function () {
    $actor = User::factory()->onboarded()->create();
    $legacy = BureaucracyCase::factory()->for($actor)->create(['fact_version' => 8]);
    $dossier = app(EnsureAccountHolder::class)->dossier($actor);
    expect($dossier->id)->toBe($legacy->id)->and($dossier->fact_version)->toBe(8);
    $other = app(EnsureAccountHolder::class)->person(User::factory()->onboarded()->create());
    $legacy->update(['person_id' => $other->id]);
    expect(fn () => app(EnsureAccountHolder::class)->dossier($actor))->toThrow(LogicException::class);
});

test('dependent access requires a current reviewed guardian record, not a family checkbox', function () {
    config(['bureaucracy_family.guardian_policy_version' => 'synthetic-policy-1']);
    $guardian = User::factory()->onboarded()->create(['is_admin' => true]);
    $reviewer = User::factory()->onboarded()->create(['is_admin' => true]);
    $pending = app(ManageDependents::class)->request($guardian, 'Synthetic child');
    $child = $pending->person;
    expect($child->account_user_id)->toBeNull()
        ->and($child->dossier->user_id)->toBeNull()
        ->and(app(PersonAccess::class)->allows($guardian, $child, AccessScope::ViewPlan))->toBeFalse();
    expect(fn () => app(ManageDependents::class)->review($guardian, $pending, 'synthetic-policy-1', 'synthetic-reviewed-evidence', now()->addYear()))->toThrow(AuthorizationException::class);
    app(ManageDependents::class)->review($reviewer, $pending, 'synthetic-policy-1', 'synthetic-reviewed-evidence', now()->addYear());
    expect(app(PersonAccess::class)->allows($guardian, $child, AccessScope::ViewPlan))->toBeTrue()
        ->and(app(PersonAccess::class)->allows($reviewer, $child, AccessScope::ViewFacts))->toBeFalse();
    config(['bureaucracy_family.guardian_policy_version' => 'synthetic-policy-2']);
    expect(app(PersonAccess::class)->allows($guardian, $child, AccessScope::ViewPlan))->toBeFalse();
});

test('self person creation is idempotent and labels stay encrypted', function () {
    $user = User::factory()->onboarded()->create(['name' => 'Synthetic private label']);
    $first = app(EnsureAccountHolder::class)->person($user);
    $second = app(EnsureAccountHolder::class)->person($user);
    expect($first->id)->toBe($second->id)
        ->and($first->getRawOriginal('display_label'))->not->toContain($user->name)
        ->and($first->toArray())->not->toHaveKey('display_label');
});
