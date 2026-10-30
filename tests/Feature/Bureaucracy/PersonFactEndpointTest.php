<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Models\User;

test('person fact endpoints distinguish a real change from a correction with stale-write protection', function () {
    // No German level on the profile, so the record starts with no answers at all.
    $actor = User::factory()->onboarded()->create(['german_level' => null]);
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/paperwork/v2/people/'.$case->person_id.'/facts';
    $this->actingAs($actor)->getJson($url)->assertSuccessful()->assertJsonPath('revision', 1)->assertJsonPath('values', []);
    $this->putJson($url.'/current_residence_title', [
        'value' => 'blue_card', 'effective_from' => '2024-01-01', 'expected_revision' => 1, 'answer_state' => 'value',
    ])->assertSuccessful()->assertJsonPath('revision', 2);
    $current = $this->getJson($url)->assertSuccessful()->assertJsonPath('values.current_residence_title', 'blue_card')->json('evidence.current_residence_title.fact_id');
    $this->postJson($url.'/'.$current.'/corrections', ['value' => 'other', 'expected_revision' => 1])->assertConflict();
    $this->postJson($url.'/'.$current.'/corrections', ['value' => 'standard_work_permit', 'expected_revision' => 2])
        ->assertSuccessful()->assertJsonPath('revision', 3);
    $this->getJson($url)->assertSuccessful()->assertJsonPath('values.current_residence_title', 'standard_work_permit');
    $this->putJson($url.'/invented_legal_status', ['value' => 'eligible', 'expected_revision' => 3])->assertUnprocessable();
});

test('a view-plan grant cannot read or change another adults facts through API endpoints', function () {
    $helper = User::factory()->onboarded()->create();
    $subject = User::factory()->onboarded()->create();
    $one = app(EnsureAccountHolder::class)->dossier($helper);
    $two = app(EnsureAccountHolder::class)->dossier($subject);
    $invite = app(ManageDelegation::class)->invite($helper, $one->person->workspace, $subject->email, ['view_plan']);
    app(ManageDelegation::class)->accept($subject, $invite['token'], ['view_plan']);
    $url = '/paperwork/v2/people/'.$two->person_id.'/facts';
    $this->actingAs($helper)->getJson($url)->assertForbidden();
    $this->putJson($url.'/livelihood_secured', ['value' => 'yes', 'expected_revision' => 1])->assertForbidden();
    expect($two->facts()->count())->toBe(0);
});

test('new commands keep dates and omitted answers distinct without session disclosure', function () {
    $actor = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/paperwork/v2/people/'.$case->person_id.'/facts';
    $this->actingAs($actor)->put($url.'/moved_in_at', ['value' => '2026-02-30', 'expected_revision' => 1], ['Accept' => 'text/html'])
        ->assertUnprocessable()->assertSessionMissing('_old_input');
    $this->putJson($url.'/livelihood_secured', ['value' => null, 'answer_state' => 'declined', 'expected_revision' => 1])->assertSuccessful();
    $this->getJson($url)->assertJsonPath('states.livelihood_secured', 'declined')->assertJsonMissingPath('values.livelihood_secured');
});

test('🔒 the account holder\'s German level is their profile\'s: read from it, saved to it, never a second copy', function () {
    $actor = User::factory()->onboarded()->create(['german_level' => 'a2']);
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $url = '/paperwork/v2/people/'.$case->person_id.'/facts';
    $this->actingAs($actor)->getJson($url)->assertJsonPath('values.german_level', 'a2')->assertJsonPath('evidence.german_level.source', 'profile');

    // Saved in Paperwork: the profile changes, and the record keeps no level of its own.
    $this->putJson($url.'/german_level', ['value' => 'b1', 'expected_revision' => $case->fresh()->fact_version])->assertSuccessful();
    expect($actor->fresh()->german_level->value)->toBe('b1')
        ->and($case->facts()->where('key', 'german_level')->where('state', 'confirmed')->exists())->toBeFalse();

    // Changed on the profile: Paperwork reads it at once, and its record moves on a revision.
    $revision = $case->fresh()->fact_version;
    $actor->fresh()->update(['german_level' => 'c1']);
    $this->getJson($url)->assertJsonPath('values.german_level', 'c1')->assertJsonPath('revision', $revision + 1);

    // "Rather not say" has no level: the profile keeps none and the record keeps only that.
    $this->putJson($url.'/german_level', ['value' => null, 'answer_state' => 'declined', 'expected_revision' => $revision + 1])->assertSuccessful();
    expect($actor->fresh()->german_level)->toBeNull();
    $this->getJson($url)->assertJsonPath('states.german_level', 'declined')->assertJsonMissingPath('values.german_level');
});
