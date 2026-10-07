<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\PersonAccess;
use App\Models\BureaucracyAccessGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

test('revocation immediately denies stale loaded grants and leaves the subjects facts intact', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $case = app(CaseFactStore::class)->synchronizeConfirmedFacts($subject, ['german_level' => 'b1'], 'onboarding');
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_facts']);
    $person = app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_facts']);
    $grant = BureaucracyAccessGrant::query()->where('person_id', $person->id)->sole();
    $person->load('grants');
    expect(app(PersonAccess::class)->allows($helper, $person, AccessScope::ViewFacts))->toBeTrue();
    app(ManageDelegation::class)->revoke($subject, $grant);
    expect(app(PersonAccess::class)->allows($helper, $person, AccessScope::ViewFacts))->toBeFalse()
        ->and(app(PersonAccess::class)->allows($subject, $person, AccessScope::ViewFacts))->toBeTrue()
        ->and($case->facts()->sole()->value)->toBe('b1');
});

test('expired invitations and grants are denied at their exact boundary', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_plan']);
    $this->travelTo($invite['invitation']->expires_at);
    expect(fn () => app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan']))->toThrow(AuthorizationException::class);
    $freshInvite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_plan']);
    $person = app(ManageDelegation::class)->accept($subject, $freshInvite['token'], ['view_plan']);
    $grant = BureaucracyAccessGrant::query()->where('person_id', $person->id)->sole();
    $this->travelTo($grant->expires_at);
    expect(app(PersonAccess::class)->allows($helper, $person, AccessScope::ViewPlan))->toBeFalse();
});

test('a helper may leave but cannot grant another helper access or revoke unrelated grants', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $outsider = User::factory()->onboarded()->create();
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['edit_facts']);
    $person = app(ManageDelegation::class)->accept($subject, $invite['token'], ['edit_facts']);
    $grant = BureaucracyAccessGrant::query()->where('person_id', $person->id)->sole();
    expect(app(PersonAccess::class)->canManage($helper, $person))->toBeFalse();
    expect(fn () => app(ManageDelegation::class)->revoke($outsider, $grant))->toThrow(AuthorizationException::class);
    app(ManageDelegation::class)->revoke($helper, $grant);
    expect(app(PersonAccess::class)->allows($helper, $person, AccessScope::EditFacts))->toBeFalse();
});

test('cancelling an already accepted invitation explicitly requires revoking its grant instead', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $invite = app(ManageDelegation::class)->invite($helper, app(EnsureAccountHolder::class)->person($helper)->workspace, $subject->email, ['view_plan']);
    $person = app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan']);
    expect(fn () => app(ManageDelegation::class)->cancelInvitation($helper, $invite['invitation']))->toThrow(ValidationException::class);
});
