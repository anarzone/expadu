<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Models\BureaucracyCaseQuestion;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()));
    $store->activate($release->id, null);
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
        'structured_answers' => ['sponsor' => 'non_eu'],
        'expected_path' => 'family_reunification',
        'coverage' => 'needs_information',
        'matched' => [
            'case.family.first_permit.prepare',
        ],
        'unknown' => ['case.family.first_permit.sponsor_pending_review'],
        'missing' => ['livelihood_secured'],
        'sections' => [
            'do_now' => [
                'case.family.first_permit.prepare',
                'core.anmeldung',
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
            'sponsor' => 'non_eu',
            'marital_household_continues' => true,
            'family_residence_permit_held_since' => '2023-08-03',
            'weekly_work_hours' => 25,
            'livelihood_secured' => 'yes',
            'housing_sufficient' => 'yes',
            'legal_social_knowledge_proved' => 'yes',
        ],
        'expected_path' => 'family_reunification',
        'coverage' => 'matched',
        // A settlement goal does not suppress independent renewal preparation.
        'matched' => ['case.family.renew.continuing_household', 'case.family.settlement.general_coming_up', 'case.family.settlement.spouse_18c_option'],
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
            'sponsor' => 'non_eu',
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

test('real onboarding and explicit follow ups produce the canonical reviewed case plan', function (array $journey) {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->post(route('onboarding.complete'), [
        'veedel' => 'Nippes',
        'arrival_date' => '2026-07-24',
        'arrival_planned' => false,
        'moved_in_at' => '2026-07-24',
        'address_registration_status' => 'not_registrable',
        'registration_status' => 'not_registered',
        'interests' => [],
        ...$journey['onboarding'],
    ])->assertRedirect(route('bureaucracy'));

    $user->refresh();
    expect($user->bureaucracy_path)->toBeNull()
        ->and($user->onboarded_at)->not->toBeNull();
    $read = fn () => $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $initial = $read();
    expect(BureaucracyCaseQuestion::query()->count())->toBe(0);

    $answers = $journey['structured_answers'];
    $remainingAnswers = $answers;
    $session = $remainingAnswers === [] ? null : $this->postJson('/bureaucracy/v2/people/'.$initial['person_id'].'/question-sessions', [
        'jurisdiction' => 'de-nrw-cologne', 'request_id' => (string) Str::uuid(),
    ])->assertCreated()->json('session_id');
    $offeredKeys = [];
    $attempts = 0;

    while ($remainingAnswers !== []) {
        expect(++$attempts)->toBeLessThanOrEqual(20);
        $response = $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/next', ['request_id' => (string) Str::uuid()])
            ->assertSuccessful()->json();
        if ($response['status'] === 'paused') {
            $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/resume', ['revisit_deferred' => false])->assertNoContent();

            continue;
        }
        expect($response['status'])->toBe('offered', 'Missing known answers: '.implode(', ', array_keys($remainingAnswers)));
        $question = $response['question'];
        $key = $question['fact_key'];
        expect($offeredKeys)->not->toContain($key);
        $offeredKeys[] = $key;
        if (! array_key_exists($key, $remainingAnswers)) {
            // A historical persona does not know every orientation detail.
            // Skip explicitly instead of manufacturing an answer to finish QA.
            $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/defer/'.$question['id'], ['token' => $question['token']])->assertNoContent();

            continue;
        }
        $this->postJson('/bureaucracy/v2/question-sessions/'.$session.'/answers/'.$question['id'], [
            'token' => $question['token'], 'value' => $remainingAnswers[$key],
        ])->assertSuccessful();
        unset($remainingAnswers[$key]);
    }

    $plan = $read();
    $caseGuidance = collect($plan['guidance'])->filter(fn ($row) => str_starts_with($row['id'], 'case.') && $row['kind'] !== 'context');
    expect($plan['coverage']['state'])->toBe('partial')
        ->and($caseGuidance->where('assessment', 'supported_preparation')->pluck('id')->sort()->values()->all())
        ->toBe(collect($journey['matched'])->sort()->values()->all())
        ->and($caseGuidance->where('assessment', 'needs_information')->pluck('id')->sort()->values()->all())
        ->toBe(collect($journey['unknown'])->sort()->values()->all())
        ->and(array_column($plan['guidance'], 'assessment'))->not->toContain('requirements_met');
    foreach ($journey['sections']['do_now'] ?? [] as $key) {
        expect(array_column($plan['actions'], 'source_rule_id'))->toContain($key);
    }
    foreach ([...($journey['sections']['options'] ?? []), ...($journey['sections']['coming_up'] ?? [])] as $key) {
        expect($caseGuidance->firstWhere('id', $key)['kind'])->toBe('option')
            ->and(array_column($plan['actions'], 'source_rule_id'))->not->toContain($key);
    }
    foreach ($journey['unknown'] as $key) {
        expect($caseGuidance->firstWhere('id', $key)['missing_facts'])->toBe($journey['missing']);
    }
    foreach ($journey['universal'] ?? [] as $key) {
        expect(collect($plan['guidance'])->firstWhere('id', $key)['kind'])->toBe('context');
    }
    $facts = $this->getJson('/bureaucracy/v2/people/'.$initial['person_id'].'/facts')->assertSuccessful()->json();
    foreach ($answers as $key => $value) {
        expect($facts['values'][$key])->toBe($value)
            ->and($facts['evidence'][$key]['source'])->toBe($key === 'sponsor' ? 'attributed_report' : 'manual');
    }
    expect($facts['values']['current_residence_title'])->toBe($journey['onboarding']['current_residence_title'])
        // Onboarding never writes the employment track. It may be asked later, because the
        // checked standard-permit and Chancenkarte cards depend on it.
        ->and($facts['values'])->not->toHaveKey('permit_track');
    expect($read())->toBe($plan);
})->with('investigated onboarding journeys');
