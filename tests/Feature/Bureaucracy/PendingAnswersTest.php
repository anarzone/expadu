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
 * Onboarding is only safe to strip once a skipped answer can be finished
 * later. It could not be: QuestionSelector drives the case plan, so it only
 * looks at authoritative rules, and a fact gating a published-but-unapproved
 * branch was never asked for by anything.
 *
 * Measured against the real catalogue, that hole is `entry_mode` — 17
 * `applies_if` references, more than any other fact — invisible to standard
 * employees, students and freelancers.
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

it('asks for a fact the case plan is blind to', function (string $label, array $facts) {
    $case = pendingCase($facts);

    $planQuestions = app(QuestionSelector::class)->rankedFactKeys(
        $case,
        app(CaseMatcher::class)->match($case),
    );

    // The plan has nothing to ask: no approved rule covers this branch.
    expect($planQuestions)->toBe([], "{$label} unexpectedly has plan questions");

    // The prompt does, because a published rule is still waiting on it.
    expect(app(PendingAnswers::class)->forCase($case))->toContain('entry_mode');
})->with([
    'standard employee' => ['standard employee', [
        'citizenship_group' => 'non_eu', 'purpose' => 'employment',
        'permit_track' => 'standard', 'current_residence_title' => 'standard_work_permit',
    ]],
    'student' => ['student', [
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa',
    ]],
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

it('covers everything the case plan already asks, and more', function () {
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
        ->and($pending)->toContain('entry_mode');
});

it('leaves a contested fact to the conflict flow', function () {
    $case = pendingCase([
        'citizenship_group' => 'non_eu', 'purpose' => 'study',
        'current_residence_title' => 'national_d_visa',
    ]);

    BureaucracyFactConflict::factory()->create([
        'case_id' => $case->id,
        'fact_key' => 'entry_mode',
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
