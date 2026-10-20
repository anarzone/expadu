<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\PendingAnswers;
use App\Bureaucracy\Cases\QuestionSelector;
use App\Bureaucracy\Facts\FactRegistry;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
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
