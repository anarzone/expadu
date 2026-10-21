<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\RecordedAppointmentsBetween;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyPerson;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
 * Art. 18: once the holder stops processing, Paperwork keeps the record but doesn't use
 * it. Nothing assesses it, nothing shows it to anyone else, and it can't be changed
 * until they resume; they can still read, download and delete it.
 */

/** A holder with an active record, stopped through the API as the page does. */
function stoppedHolder($test): array
{
    $holder = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($holder);
    app(EnsureAccountHolder::class)->dossier($holder);
    $test->actingAs($holder)->putJson("/bureaucracy/v2/people/{$person->id}/processing-restriction", ['stopped' => true])->assertOk();

    return [$holder, $person->fresh()];
}

function stoppedSharedWith(User $holder, BureaucracyPerson $person): User
{
    $helper = User::factory()->onboarded()->create();
    BureaucracyAccessGrant::query()->create([
        'person_id' => $person->id, 'grantee_user_id' => $helper->id, 'grantor_user_id' => $holder->id,
        'authority_basis' => 'adult_acceptance', 'notice_version' => (string) config('bureaucracy_family.sharing_notice_version'),
        'scopes' => ['view_plan', 'view_facts', 'edit_facts', 'manage_process', 'manage_evidence'],
        'accepted_at' => now()->subDay(), 'expires_at' => now()->addYear(),
    ]);

    return $helper;
}

test('🔒 while stopped, nothing uses the record: no plan, no appointments, no assessment', function () {
    [$holder, $person] = stoppedHolder($this);

    $this->getJson('/bureaucracy/v2/plan')->assertOk()
        ->assertJsonPath('state', 'processing_stopped')->assertJsonPath('plan', null)
        ->assertJsonPath('stopped_at', $person->processing_stopped_at->toIso8601String());
    // Today, Your day, Composer, reminders and alerts all start from these.
    expect(app(AccountHolderPlan::class)->person($holder))->toBeNull()
        ->and(app(AccountHolderPlan::class)->for($holder))->toBeNull()
        ->and(app(RecordedAppointmentsBetween::class)->for($holder, CarbonImmutable::now()->subYear(), CarbonImmutable::now()->addYear()))->toBe([]);
    $this->getJson("/bureaucracy/v2/people/{$person->id}/plan?jurisdiction=de-nrw-cologne")->assertConflict()
        ->assertJsonPath('message', PersonAccess::STOPPED);

    // Resuming brings the plan back.
    $this->putJson("/bureaucracy/v2/people/{$person->id}/processing-restriction", ['stopped' => false])->assertOk();
    $this->getJson('/bureaucracy/v2/plan')->assertOk()->assertJsonPath('state', 'ready');
});

test('🔒 while stopped, the record is read-only, but it can still be downloaded', function () {
    [, $person] = stoppedHolder($this);

    $this->putJson("/bureaucracy/v2/people/{$person->id}/facts/citizenship_group", [
        'value' => 'eu', 'answer_state' => 'value', 'expected_revision' => 1,
    ])->assertConflict()->assertJsonPath('message', PersonAccess::STOPPED);
    expect($person->dossier->facts()->count())->toBe(0);

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson("/bureaucracy/v2/people/{$person->id}/export")->assertSuccessful()->assertJsonPath('person.id', $person->id);
});

test('🔒 while stopped, someone it is shared with has no access, and gets it back on resume', function () {
    [$holder, $person] = stoppedHolder($this);
    $helper = stoppedSharedWith($holder, $person);

    $this->actingAs($helper);
    expect($this->getJson("/bureaucracy/v2/people/{$person->id}")->status())->toBeIn([403, 404])
        ->and($this->getJson("/bureaucracy/v2/people/{$person->id}/plan?jurisdiction=de-nrw-cologne")->status())->toBeIn([403, 404])
        ->and(app(PersonAccess::class)->scopesFor($helper, $person))->toBe([]);

    $this->actingAs($holder)->putJson("/bureaucracy/v2/people/{$person->id}/processing-restriction", ['stopped' => false])->assertOk();
    $this->actingAs($helper)->getJson("/bureaucracy/v2/people/{$person->id}")->assertOk();
});

test('🔒 a record its holder deleted reads as deleted, not as unavailable', function () {
    $holder = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->person($holder);
    app(EnsureAccountHolder::class)->dossier($holder);

    $this->actingAs($holder)->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson("/bureaucracy/v2/people/{$person->id}", ['confirm' => true])->assertNoContent();

    $this->getJson('/bureaucracy/v2/plan')->assertOk()
        ->assertJsonPath('state', 'erased')->assertJsonPath('plan', null)
        ->assertJsonPath('erased_at', $person->fresh()->erased_at->toIso8601String());
});
