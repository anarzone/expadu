<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PlanSnapshotStore;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;
use App\Models\Task;
use App\Models\User;

/**
 * @return array<string, mixed>
 */
function caseQuestionCondition(string $factKey): array
{
    return [$factKey => match ($factKey) {
        'residence_title_expires_at' => '2026-10-01',
        'blue_card_qualifying_months' => 12,
        'marital_household_continues' => true,
        default => 'settlement_permit',
    }];
}

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
    Task::factory()->create([
        'key' => "case.question.{$factKey}",
        'title' => 'Verified question-gated task',
        'type' => 'task',
        'situation' => ['core'],
        'applies_if' => [caseQuestionCondition($factKey)],
        'phase' => 'arrival',
        'depends_on' => [],
        'deadline_type' => 'none',
        'deadline_days' => null,
        'urgency' => 'high',
        'is_published' => true,
        'jurisdiction' => 'de-nrw-cologne',
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => 'Residence Act',
                'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
            ],
            [
                'kind' => 'implementation',
                'label' => 'Stadt Köln',
                'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
            ],
        ],
        'review_status' => 'approved',
        'source_verification' => 'dual_source',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-03.1',
        'verified_at' => '2026-08-03',
        'review_due_at' => '2027-08-03',
        'conflicts_with' => [],
        'coverage_scope' => 'case',
    ]);
    $match = app(CaseMatcher::class)->match($case);
    app(PlanSnapshotStore::class)->store($case);
    $question = app(QuestionSelector::class)->select($case, $match);

    expect($question)->toBeInstanceOf(BureaucracyCaseQuestion::class);

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

test('an answered question rejects a different replay value', function () {
    [$user, $case, $question] = caseQuestionFixture();

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => 'settlement_permit',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->post(route('bureaucracy.case-question.answer', $question), [
            'value' => 'blue_card',
        ])
        ->assertForbidden();

    expect(BureaucracyCaseFact::where('case_id', $case->id)->where('key', 'case_goal')->count())->toBe(1)
        ->and(BureaucracyCaseFact::where('case_id', $case->id)->where('key', 'case_goal')->first()->value)
        ->toBe('settlement_permit');
});

test('a stale question cannot conflict with or overwrite a newly confirmed fact', function () {
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
        ->assertForbidden();

    expect($existing->fresh()->state)->toBe('confirmed')
        ->and($existing->fresh()->value)->toBe('blue_card')
        ->and(BureaucracyFactConflict::where('case_id', $case->id)->where('status', 'unresolved')->count())
        ->toBe(0)
        ->and($question->fresh()->answered_at)->toBeNull()
        ->and($case->fresh()->fact_version)->toBe(1);
});
