<?php

use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;
use App\Models\User;

/**
 * @return array{0: User, 1: BureaucracyCase, 2: BureaucracyCaseQuestion}
 */
function caseQuestionFixture(string $factKey = 'case_goal'): array
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();
    $question = BureaucracyCaseQuestion::factory()->create([
        'case_id' => $case->id,
        'fact_key' => $factKey,
        'attempt' => 1,
        'asked_at' => now(),
        'answered_at' => null,
        'outcome' => null,
    ]);

    return [$user, $case, $question];
}

test('structured case question answers require authentication', function () {
    [, , $question] = caseQuestionFixture();

    $this->post(route('bureaucracy.case-question.answer', $question), [
        'value' => 'settlement_permit',
    ])->assertRedirect(route('login'));
});

test('a user cannot answer another users case question', function () {
    [, , $question] = caseQuestionFixture();
    $otherUser = User::factory()->onboarded()->create();

    $this->actingAs($otherUser)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => 'settlement_permit',
        ])
        ->assertForbidden();
});

test('structured answers are validated using the server registered fact type', function (string $factKey, mixed $invalidValue) {
    [$user, , $question] = caseQuestionFixture($factKey);

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => $invalidValue,
        ])
        ->assertSessionHasErrors('value');

    expect($question->fresh()->answered_at)->toBeNull();
})->with([
    'enum outside catalogue' => ['case_goal', 'invented_route'],
    'invalid ISO date' => ['residence_title_expires_at', 'tomorrow'],
    'non integer months' => ['blue_card_qualifying_months', 'twelve'],
    'non boolean household answer' => ['marital_household_continues', 'perhaps'],
]);

test('a server issued structured answer becomes a confirmed fact atomically', function () {
    [$user, $case, $question] = caseQuestionFixture();

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => 'settlement_permit',
        ])
        ->assertRedirect();

    $fact = BureaucracyCaseFact::query()
        ->where('case_id', $case->id)
        ->where('key', 'case_goal')
        ->sole();

    expect($fact->value)->toBe('settlement_permit')
        ->and($fact->state)->toBe('confirmed')
        ->and($fact->source)->toBe('structured_interview')
        ->and($fact->source_reference)->toBe("question:{$question->id}")
        ->and($question->fresh()->answered_at)->not->toBeNull()
        ->and($question->fresh()->outcome)->toBe('answered')
        ->and($case->fresh()->fact_version)->toBe(2);
});

test('submitting the same answered question twice is idempotent', function () {
    [$user, $case, $question] = caseQuestionFixture();

    $payload = ['value' => 'settlement_permit'];

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), $payload)
        ->assertRedirect();
    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), $payload)
        ->assertRedirect();

    expect(BureaucracyCaseFact::where('case_id', $case->id)->where('key', 'case_goal')->count())->toBe(1)
        ->and($case->fresh()->fact_version)->toBe(2);
});

test('a conflicting answer is retained for review and never overwrites the confirmed fact', function () {
    [$user, $case, $question] = caseQuestionFixture();
    $existing = BureaucracyCaseFact::factory()->create([
        'case_id' => $case->id,
        'key' => 'case_goal',
        'value' => 'blue_card',
        'state' => 'confirmed',
        'confirmed_at' => now(),
        'reconfirm_at' => now()->addYear(),
        'superseded_at' => null,
    ]);

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => 'settlement_permit',
        ])
        ->assertSessionHasErrors('value');

    expect($existing->fresh()->state)->toBe('confirmed')
        ->and($existing->fresh()->value)->toBe('blue_card')
        ->and(BureaucracyCaseFact::where('case_id', $case->id)->where('state', 'candidate')->value('value'))
        ->toBe('settlement_permit')
        ->and(BureaucracyFactConflict::where('case_id', $case->id)->where('status', 'unresolved')->count())
        ->toBe(1)
        ->and($question->fresh()->outcome)->toBe('conflict')
        ->and($case->fresh()->fact_version)->toBe(1);
});
