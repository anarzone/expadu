<?php

use App\Bureaucracy\People\ManageDependents;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

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
