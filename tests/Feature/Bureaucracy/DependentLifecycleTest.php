<?php

use App\Bureaucracy\People\AccessScope;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\ManageDependents;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config(['bureaucracy_family.guardian_policy_version' => 'synthetic-guardian-policy']);
    $this->reviewer = User::factory()->onboarded()->create(['is_admin' => true]);
});

function approvedDependent(User $guardian, User $reviewer): BureaucracyGuardianAuthority
{
    $authority = app(ManageDependents::class)->request($guardian, 'Synthetic child');
    app(ManageDependents::class)->review($reviewer, $authority, 'synthetic-guardian-policy', 'synthetic-evidence', now()->addMonth());

    return $authority->fresh();
}

function coGuardian(BureaucracyPerson $child, User $guardian, User $reviewer): BureaucracyGuardianAuthority
{
    $authority = BureaucracyGuardianAuthority::query()->create(['person_id' => $child->id, 'guardian_user_id' => $guardian->id]);
    app(ManageDependents::class)->review($reviewer, $authority, 'synthetic-guardian-policy', 'synthetic-evidence', now()->addMonth());

    return $authority->fresh();
}

function seedDependentFact(BureaucracyPerson $child): void
{
    BureaucracyCaseFact::factory()->create(['case_id' => $child->dossier->id, 'key' => 'german_level', 'value' => 'a1']);
}

test('deleting the only guardian erases the dependent dossier', function () {
    $guardian = User::factory()->onboarded()->create();
    $child = approvedDependent($guardian, $this->reviewer)->person;
    seedDependentFact($child);

    $guardian->delete();

    $child->refresh();
    expect($child->record_status)->toBe('erased')
        ->and($child->display_label)->toBeNull()
        ->and(BureaucracyCaseFact::query()->where('case_id', $child->dossier->id)->count())->toBe(0)
        ->and($child->dossier->status)->toBe('erased')
        ->and(BureaucracyOutboxEvent::query()->where('event_type', 'person.erased')->where('aggregate_id', $child->id)->exists())->toBeTrue();
});

test('deleting one guardian keeps a dependent another guardian still looks after', function () {
    $guardian = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $child = approvedDependent($guardian, $this->reviewer)->person;
    coGuardian($child, $other, $this->reviewer);
    seedDependentFact($child);

    $guardian->delete();

    expect($child->fresh()->record_status)->toBe('active')
        ->and(BureaucracyCaseFact::query()->where('case_id', $child->dossier->id)->count())->toBe(1);
});

test('deleting a guardian with an unreviewed request erases that dependent', function () {
    $guardian = User::factory()->onboarded()->create();
    $child = app(ManageDependents::class)->request($guardian, 'Synthetic child')->person;

    $guardian->delete();

    expect($child->fresh()->record_status)->toBe('erased');
});

test('revoking the last guardian authority erases the dependent in the same change', function () {
    $guardian = User::factory()->onboarded()->create();
    $authority = approvedDependent($guardian, $this->reviewer);
    $child = $authority->person;
    seedDependentFact($child);

    app(ManageDependents::class)->revoke($guardian, $authority);

    expect($child->fresh()->record_status)->toBe('erased')
        ->and(BureaucracyCaseFact::query()->where('case_id', $child->dossier->id)->count())->toBe(0);
});

test('revoking one of two guardian authorities keeps the dependent', function () {
    $guardian = User::factory()->onboarded()->create();
    $authority = approvedDependent($guardian, $this->reviewer);
    coGuardian($authority->person, User::factory()->onboarded()->create(), $this->reviewer);

    app(ManageDependents::class)->revoke($guardian, $authority);

    expect($authority->person->fresh()->record_status)->toBe('active');
});

test('the sweep erases dependents whose authority expired or whose request was never reviewed', function () {
    $expired = approvedDependent(User::factory()->onboarded()->create(), $this->reviewer);
    $live = approvedDependent(User::factory()->onboarded()->create(), $this->reviewer);
    $stale = app(ManageDependents::class)->request(User::factory()->onboarded()->create(), 'Synthetic child');
    $fresh = app(ManageDependents::class)->request(User::factory()->onboarded()->create(), 'Synthetic child');
    seedDependentFact($expired->person);
    $expired->forceFill(['expires_at' => now()->subMinute()])->save();
    $stale->forceFill(['created_at' => now()->subDays(31)])->save();

    $this->artisan('bureaucracy:erase-unguarded-dependents')->assertSuccessful();

    expect($expired->person->fresh()->record_status)->toBe('erased')
        ->and(BureaucracyCaseFact::query()->where('case_id', $expired->person->dossier->id)->count())->toBe(0)
        ->and($stale->person->fresh()->record_status)->toBe('erased')
        ->and($live->person->fresh()->record_status)->toBe('active')
        ->and($fresh->person->fresh()->record_status)->toBe('active');
});

test('the unguarded dependent sweep is scheduled on one server without overlap', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command ?? '', 'bureaucracy:erase-unguarded-dependents'));

    expect($event)->not->toBeNull()
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('a co-guardian cannot strip another guardians reviewed access through grant revocation', function () {
    $guardian = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $authority = approvedDependent($guardian, $this->reviewer);
    coGuardian($authority->person, $other, $this->reviewer);
    $grant = BureaucracyAccessGrant::query()->where('guardian_authority_id', $authority->id)->sole();

    $this->actingAs($other)->deleteJson('/bureaucracy/v2/grants/'.$grant->id)->assertUnprocessable();
    expect(fn () => app(ManageDelegation::class)->revoke($guardian, $grant))->toThrow(ValidationException::class);

    expect($grant->fresh()->revoked_at)->toBeNull()
        ->and(app(PersonAccess::class)->allows($guardian, $authority->person, AccessScope::ViewFacts))->toBeTrue();
});

test('exporting a dependent requires view access as well as guardian authority', function () {
    $guardian = User::factory()->onboarded()->create();
    $child = approvedDependent($guardian, $this->reviewer)->person;
    expect(app(PersonDataLifecycle::class)->export($guardian, $child)['person']['id'])->toBe($child->id);

    BureaucracyAccessGrant::query()->where('person_id', $child->id)->update(['revoked_at' => now()]);

    expect(app(PersonAccess::class)->canManage($guardian, $child))->toBeTrue()
        ->and(fn () => app(PersonDataLifecycle::class)->export($guardian, $child))->toThrow(AuthorizationException::class);
});
