<?php

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;

/**
 * @return array{0: User, 1: BureaucracyCase, 2: BureaucracyCaseFact, 3: BureaucracyCaseFact, 4: BureaucracyFactConflict}
 */
function caseConflictFixture(): array
{
    $user = User::factory()->onboarded()->create();
    $case = BureaucracyCase::factory()->for($user)->create();
    $existing = BureaucracyCaseFact::factory()->create([
        'case_id' => $case->id,
        'key' => 'case_goal',
        'value' => 'blue_card',
        'state' => 'confirmed',
    ]);
    $candidate = BureaucracyCaseFact::factory()->candidate()->create([
        'case_id' => $case->id,
        'key' => 'case_goal',
        'value' => 'settlement_permit',
    ]);
    $conflict = BureaucracyFactConflict::factory()->create([
        'case_id' => $case->id,
        'fact_key' => 'case_goal',
        'existing_fact_id' => $existing->id,
        'candidate_fact_id' => $candidate->id,
        'status' => 'unresolved',
    ]);

    return [$user, $case, $existing, $candidate, $conflict];
}

test('case conflict resolution requires authentication', function () {
    [, , , , $conflict] = caseConflictFixture();

    $this->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
        'choice' => 'candidate',
    ])->assertRedirect(route('login'));
});

test('a user cannot resolve another users case conflict', function () {
    [, , , , $conflict] = caseConflictFixture();
    $otherUser = User::factory()->onboarded()->create();

    $this->actingAs($otherUser)
        ->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
            'choice' => 'candidate',
        ])
        ->assertForbidden();
});

test('case conflict resolution accepts only the two server-defined choices', function () {
    [$user, , , , $conflict] = caseConflictFixture();

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
            'choice' => 'invented',
        ])
        ->assertSessionHasErrors('choice');

    expect($conflict->fresh()->status)->toBe('unresolved');
});

test('the user can confirm the newer answer without silently losing history', function () {
    [$user, $case, $existing, $candidate, $conflict] = caseConflictFixture();

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
            'choice' => 'candidate',
        ])
        ->assertRedirect();

    expect($conflict->fresh()->status)->toBe('resolved')
        ->and($conflict->fresh()->resolved_fact_id)->toBe($candidate->id)
        ->and($candidate->fresh()->state)->toBe('confirmed')
        ->and($existing->fresh()->state)->toBe('superseded')
        ->and($case->fresh()->fact_version)->toBe(2);
});

test('a resolved conflict cannot be changed through a replay', function () {
    [$user, , , , $conflict] = caseConflictFixture();

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
            'choice' => 'existing',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-conflict.resolve', $conflict), [
            'choice' => 'candidate',
        ])
        ->assertForbidden();
});
