<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PendingAnswers;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyFactConflict;
use App\Models\User;

/**
 * Skipped answers remain available through essential orientation or approved
 * rule dependencies. Unapproved content must not create an endless interview.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

/**
 * @param  array<string, mixed>  $facts
 */
function pendingCase(array $facts): BureaucracyCase
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);

    $case = BureaucracyCase::factory()->for($user)->create();

    foreach ($facts as $key => $value) {
        BureaucracyCaseFact::factory()->create([
            'case_id' => $case->id,
            'key' => $key,
            'value' => $value,
            'state' => 'confirmed',
            'confirmed_at' => now(),
            'reconfirm_at' => now()->addYear(),
            'superseded_at' => null,
        ]);
    }

    return $case;
}

it('offers essential orientation without interviewing from unapproved branch rules', function (string $label, array $facts, array $route = []) {
    $case = pendingCase($facts);

    $planQuestions = app(QuestionSelector::class)->rankedFactKeys(
        $case,
        app(CaseMatcher::class)->match($case),
    );

    // Approved basic preparation applies across purposes, even when the
    // residence route has no reviewed module. It must not ask legacy selectors.
    // $route lists registered facts that checked residence cards for this purpose depend on.
    expect($planQuestions)->toEqualCanonicalizing([
        'arrival_planned', 'registration_status', 'health_coverage_confirmed',
        'tax_id_available', 'bank_account_help_needed', ...$route,
    ], "{$label}: only approved preparation and checked routes should ask follow-ups");

    expect(app(PendingAnswers::class)->forCase($case))->toContain('residence_title_expires_at')
        ->not->toContain('business_type')->not->toContain('license_country');
})->with([
    'standard employee' => ['standard employee', [
        'citizenship_group' => 'non_eu', 'purpose' => 'employment',
        'permit_track' => 'standard', 'current_residence_title' => 'standard_work_permit',
    ], ['case_goal', 'entry_mode']],
    'student' => ['student', [
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa',
    ], ['entry_mode']],
    'freelancer' => ['freelancer', [
        'citizenship_group' => 'non_eu', 'purpose' => 'freelance',
        'current_residence_title' => 'national_d_visa',
    ]],
]);

it('stops asking once the answer is given', function () {
    $answered = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa', 'entry_mode' => 'd_visa',
    ]);

    expect(app(PendingAnswers::class)->forCase($answered))->not->toContain('entry_mode');
});

it('never asks a question that could not change what the user sees', function () {
    // A student is not on a Blue Card track, so no rule is waiting on Blue Card
    // months. Asking anyway would be a questionnaire, not a prompt.
    $case = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa',
    ]);

    expect(app(PendingAnswers::class)->forCase($case))
        ->not->toContain('blue_card_qualifying_months')
        ->not->toContain('sponsor_current_title')
        ->not->toContain('marital_household_continues');
});

it('covers the approved questions plus missing essential orientation', function () {
    $case = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'employment',
        'permit_track' => 'blue_card', 'current_residence_title' => 'blue_card',
    ]);

    $planQuestions = app(QuestionSelector::class)->rankedFactKeys(
        $case,
        app(CaseMatcher::class)->match($case),
    );
    $pending = app(PendingAnswers::class)->forCase($case);

    expect($planQuestions)->not->toBeEmpty()
        ->and(array_diff($planQuestions, $pending))->toBe([])
        ->and($pending)->toContain('residence_title_expires_at')
        ->and($pending)->not->toContain('business_type');
});

it('leaves a contested fact to the conflict flow', function () {
    $case = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa',
    ]);

    BureaucracyFactConflict::factory()->create([
        'case_id' => $case->id,
        'fact_key' => 'entry_mode',
        'existing_fact_id' => BureaucracyCaseFact::factory()->create(['case_id' => $case->id, 'key' => 'entry_mode', 'value' => 'd_visa'])->id,
        'candidate_fact_id' => BureaucracyCaseFact::factory()->candidate()->create(['case_id' => $case->id, 'key' => 'entry_mode', 'value' => 'visa_free'])->id,
        'status' => 'unresolved',
    ]);

    expect(app(PendingAnswers::class)->forCase($case))->not->toContain('entry_mode');
});

it('ranks by the registry priority, not catalogue order', function () {
    $case = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'family',
        'current_residence_title' => 'family_reunification',
    ]);

    $pending = app(PendingAnswers::class)->forCase($case);
    $registry = app(FactRegistry::class);

    $priorities = array_map(
        fn (string $key): int => $registry->definition($key)->priority,
        $pending,
    );
    $sorted = $priorities;
    rsort($sorted);

    expect($pending)->not->toBeEmpty()->and($priorities)->toBe($sorted);
});

it('surfaces an essential orientation question on the Bureaucracy page itself', function () {
    // Arrival orientation is useful without approving a student's residence route.
    $user = User::factory()->create([
        'situation' => 'student',
        'is_eu' => false,
        'bureaucracy_path' => 'student',
        'veedel' => 'Altstadt-Nord',
        'arrival_date' => now()->subMonths(2)->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ]);

    $response = $this->actingAs($user)->get('/bureaucracy');

    $response->assertSuccessful();

    $question = $response->viewData('page')['props']['casePlan']['next_question'] ?? null;

    // The payload carries the rendered question, not the fact key, so assert
    // against the registry's own wording for the highest-priority question.
    $expected = app(FactRegistry::class)->definition('arrival_planned');

    expect($question)->not->toBeNull()
        ->and($question['question'])->toBe($expected->question)
        ->and($question['type'])->toBe('boolean');
});

it('accepts the offered orientation answer and records the fact', function () {
    // The guard in AnswerCaseQuestion re-derives "the question we are asking"
    // and rejects anything else, so a fallback question was posed and then
    // refused with a 403. Asking something the app will not accept an answer
    // to is worse than not asking.
    $user = User::factory()->create([
        'situation' => 'student',
        'is_eu' => false,
        'bureaucracy_path' => 'student',
        'veedel' => 'Altstadt-Nord',
        'arrival_date' => now()->subMonths(2)->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ]);

    $this->actingAs($user)->get('/bureaucracy')->assertSuccessful();

    $question = BureaucracyCaseQuestion::query()
        ->where('fact_key', 'arrival_planned')
        ->latest('id')
        ->firstOrFail();

    $this->actingAs($user)
        ->post("/bureaucracy/case/questions/{$question->id}", ['value' => false])
        ->assertRedirect();

    expect($question->fresh()->answered_at)->not->toBeNull()
        ->and(BureaucracyCaseFact::query()
            ->where('case_id', $question->case_id)
            ->where('key', 'arrival_planned')
            ->where('state', 'confirmed')
            ->latest('id')
            ->first()
            ?->value)->toBeFalse();

    // And it stops being asked.
    expect(app(PendingAnswers::class)->forCase($question->case))->not->toContain('arrival_planned');
});
