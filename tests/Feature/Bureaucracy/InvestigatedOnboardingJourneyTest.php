<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Models\BureaucracyCaseQuestion;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-08-03 10:00:00');
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
});

dataset('investigated onboarding journeys', [
    'working on D visa and applying for a first Blue Card' => [[
        'onboarding' => [
            'situation' => 'non_eu_employee',
            'entry_mode' => 'd_visa',
            'visa_expires_at' => '2026-10-01',
            'current_residence_title' => 'national_d_visa',
            'case_goal' => 'blue_card',
        ],
        'structured_answers' => [],
        'expected_path' => 'non_eu_employee_blue_card',
        'coverage' => 'matched',
        'matched' => [
            'case.bc.first_application.prepare',
            'case.bc.first_application.submit',
        ],
        'unknown' => [],
        'sections' => [
            'do_now' => [
                'case.bc.first_application.prepare',
                'case.bc.first_application.submit',
            ],
        ],
    ]],
    'joining spouse while the sponsor Blue Card is pending' => [[
        'onboarding' => [
            'situation' => 'family_reunification',
            'entry_mode' => 'd_visa',
            'visa_expires_at' => '2026-10-01',
            'current_residence_title' => 'national_d_visa',
            'case_goal' => 'family_reunification_permit',
            'sponsor_current_title' => 'blue_card_pending',
        ],
        'structured_answers' => [],
        'expected_path' => 'family_reunification',
        'coverage' => 'needs_information',
        'matched' => [
            'case.family.first_permit.prepare',
            'case.family.register_address',
        ],
        'unknown' => ['case.family.first_permit.sponsor_pending_review'],
        'missing' => ['livelihood_secured'],
        'sections' => [
            'do_now' => [
                'case.family.first_permit.prepare',
                'case.family.register_address',
            ],
        ],
    ]],
    'Blue Card holder with B1 and twelve qualifying months' => [[
        'onboarding' => [
            'situation' => 'non_eu_employee',
            'entry_mode' => 'has_permit',
            'current_residence_title' => 'blue_card',
            'residence_title_expires_at' => '2027-10-01',
            'case_goal' => 'settlement_permit',
            'documented_german_level' => 'b1',
        ],
        'structured_answers' => [
            'blue_card_qualifying_months' => 12,
        ],
        'expected_path' => 'non_eu_employee_blue_card',
        'coverage' => 'matched',
        'matched' => ['case.bc.settlement.track_21_months'],
        'unknown' => [],
        'sections' => [
            'coming_up' => ['case.bc.settlement.track_21_months'],
        ],
    ]],
    'spouse of an 18c holder after three years' => [[
        'onboarding' => [
            'situation' => 'family_reunification',
            'entry_mode' => 'has_permit',
            'current_residence_title' => 'family_reunification',
            'residence_title_expires_at' => '2027-10-01',
            'case_goal' => 'settlement_permit',
            'sponsor_current_title' => 'settlement_permit_18c',
            'documented_german_level' => 'b1',
        ],
        'structured_answers' => [
            'marital_household_continues' => true,
            'family_residence_permit_held_since' => '2023-08-03',
            'weekly_work_hours' => 25,
            'livelihood_secured' => 'yes',
            'housing_sufficient' => 'yes',
            'legal_social_knowledge_proved' => 'yes',
        ],
        'expected_path' => 'family_reunification',
        'coverage' => 'matched',
        'matched' => ['case.family.settlement.spouse_18c_option'],
        'unknown' => [],
        'sections' => [
            'options' => ['case.family.settlement.spouse_18c_option'],
        ],
    ]],
    'spouse approaching renewal after almost four years' => [[
        'onboarding' => [
            'situation' => 'family_reunification',
            'entry_mode' => 'has_permit',
            'current_residence_title' => 'family_reunification',
            'residence_title_expires_at' => '2026-09-01',
            'case_goal' => 'renew_current_title',
            'sponsor_current_title' => 'settlement_permit_18c',
            'documented_german_level' => 'b1',
        ],
        'structured_answers' => [
            'marital_household_continues' => true,
            'family_residence_permit_held_since' => '2022-09-01',
            'weekly_work_hours' => 25,
            'livelihood_secured' => 'yes',
            'housing_sufficient' => 'yes',
            'legal_social_knowledge_proved' => 'yes',
        ],
        'expected_path' => 'family_reunification',
        'coverage' => 'matched',
        'matched' => [
            'case.family.renew.continuing_household',
            'case.family.settlement.general_coming_up',
            'case.family.settlement.spouse_18c_option',
        ],
        'unknown' => [],
        'sections' => [
            'do_now' => ['case.family.renew.continuing_household'],
            'coming_up' => ['case.family.settlement.general_coming_up'],
            'options' => ['case.family.settlement.spouse_18c_option'],
        ],
    ]],
    'unsupported current residence title' => [[
        'onboarding' => [
            'situation' => 'non_eu_employee',
            'entry_mode' => 'has_permit',
            'current_residence_title' => 'other',
            'residence_title_expires_at' => '2027-10-01',
            'case_goal' => 'blue_card',
        ],
        'structured_answers' => [],
        'expected_path' => 'non_eu_employee_blue_card',
        'coverage' => 'not_covered',
        'matched' => [],
        'unknown' => [],
        'universal' => ['case.bc.verify_status_source'],
        'sections' => [
            'not_covered' => [],
        ],
    ]],
]);

test('real onboarding and structured answers produce the reviewed case plan', function (array $journey) {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'veedel' => 'Nippes',
        'arrival_date' => '2026-07-24',
        'arrival_planned' => false,
        'address_registration_status' => 'not_registrable',
        'interests' => [],
        ...$journey['onboarding'],
    ])->assertRedirect(route('bureaucracy'));

    $user->refresh();
    expect($user->bureaucracy_path)->toBe($journey['expected_path']);

    $remainingAnswers = $journey['structured_answers'];

    while ($remainingAnswers !== []) {
        $plan = app(CurrentCasePlan::class)->for($user->fresh());
        $questionId = $plan['next_question']['id'] ?? null;
        expect($questionId)->not->toBeNull('The case stopped asking before every reviewed fact was confirmed.');

        $question = BureaucracyCaseQuestion::query()->findOrFail($questionId);
        expect(
            $remainingAnswers,
            "Unexpected structured question for {$question->fact_key}.",
        )->toHaveKey($question->fact_key);

        $this->post(route('bureaucracy.case-question.answer', $question), [
            'value' => $remainingAnswers[$question->fact_key],
        ])->assertRedirect();

        unset($remainingAnswers[$question->fact_key]);
    }

    $case = $user->fresh()->bureaucracyCase;
    $result = app(CaseMatcher::class)->match($case);
    $sections = app(CasePlanComposer::class)->compose($case, $result);

    expect($result->coverageState->value)->toBe($journey['coverage'])
        ->and($result->matchedRuleKeys)->toBe($journey['matched'])
        ->and($result->unknownRuleKeys)->toBe($journey['unknown']);

    if (isset($journey['missing'])) {
        expect($result->missingFactKeys)->toBe($journey['missing']);
    }

    if (isset($journey['universal'])) {
        expect($result->universalRuleKeys)->toBe($journey['universal']);
    }

    foreach ($journey['sections'] as $section => $expectedKeys) {
        $actualKeys = collect($sections[$section])->pluck('key')->filter()->sort()->values()->all();

        expect($actualKeys)->toBe(collect($expectedKeys)->sort()->values()->all(), $section);
    }

    foreach ($journey['structured_answers'] as $key => $value) {
        $fact = app(CaseFactStore::class)->confirmedFact($case, $key);

        expect($fact?->value)->toBe($value)
            ->and($fact?->source)->toBe('structured_interview');
    }
})->with('investigated onboarding journeys');
