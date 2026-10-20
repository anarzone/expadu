<?php

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;

test('a confirmed D visa and Blue Card goal unlock preparation without a duplicate route question', function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()));
    $store->activate($release->id, null);
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee', 'veedel' => 'Nippes',
        'arrival_date' => '2026-07-24', 'arrival_planned' => false,
        'entry_mode' => 'd_visa', 'visa_expires_at' => '2026-10-01',
        'current_residence_title' => 'national_d_visa', 'case_goal' => 'blue_card',
    ])->assertSessionHasNoErrors();
    expect($user->fresh()->onboarded_at)->not->toBeNull();
    $plan = $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $preparation = collect($plan['guidance'])->firstWhere('id', 'case.bc.first_application.prepare');
    expect($preparation['missing_facts'])->toBe([])
        ->and($preparation['assessment'])->toBe('supported_preparation')
        ->and(array_column($plan['actions'], 'source_rule_id'))->toContain('case.bc.first_application.prepare', 'case.bc.first_application.submit');
    $facts = $this->getJson('/bureaucracy/v2/people/'.$plan['person_id'].'/facts')->assertSuccessful()->json('values');
    expect($facts)->not->toHaveKey('permit_track')
        ->and($facts['current_residence_title'])->toBe('national_d_visa')
        ->and($facts['case_goal'])->toBe('blue_card');
});

test('a visa holder is not told to submit a Blue Card application without confirmed intent', function (?string $goal, string $state) {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all());
    $assessment = (new AssessPerson)->assess(new AssessmentInput([
        'values' => ['citizenship_group' => 'non_eu', 'purpose' => 'employment',
            'current_residence_title' => 'national_d_visa', 'entry_mode' => 'd_visa', 'case_goal' => $goal],
        'states' => ['case_goal' => $state],
    ], [], [], $catalogue, 'de-nrw-cologne', CarbonImmutable::now(), $goal))->toArray();
    $process = collect($assessment['processes'])->firstWhere('definition_id', 'residence.blue_card.first');
    // The reviewed preparation/submission pair plus the checked appointment and visa-free cards.
    expect(collect($process['variants'])->pluck('id')->all())->toContain('case.bc.first_application.prepare', 'case.bc.first_application.submit', 'bc.blue_card');
    foreach ($process['variants'] as $variant) {
        // A different preference does not make the legal criteria fail, but it
        // cannot turn an optional application into the person's next action.
        expect($variant['actionable'])->toBeFalse();
        if (str_starts_with($variant['id'], 'case.')) {
            expect($variant['assessment'])->toBe('supported_preparation');
        }
    }
    expect(in_array('case_goal', array_column($assessment['question_dependencies'], 'fact_key'), true))
        ->toBe($state !== 'value');
})->with([
    'other goal' => ['renew_current_title', 'value'],
    'not chosen' => [null, 'unknown'],
    'disputed choice' => ['blue_card', 'conflict'],
    'stale choice' => ['blue_card', 'needs_reconfirmation'],
]);

test('a joining spouse receives one registration preparation task rather than competing copies', function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all());
    $assessment = (new AssessPerson)->assess(new AssessmentInput(['values' => [
        'arrival_planned' => false, 'citizenship_group' => 'non_eu', 'purpose' => 'family',
        'current_residence_title' => 'national_d_visa', 'entry_mode' => 'd_visa',
        'moved_in_at' => '2026-09-01',
        'registration_status' => 'not_registered',
    ]], [], [], $catalogue, 'de-nrw-cologne', CarbonImmutable::now()))->toArray();
    $registration = collect($assessment['processes'])->firstWhere('definition_id', 'address.registration');
    expect(array_column($registration['variants'], 'id'))->toBe(['core.anmeldung'])
        ->and($registration['variants'][0]['actionable'])->toBeTrue()
        ->and(Task::where('key', 'case.family.register_address')->firstOrFail()->is_published)->toBeFalse();
});

test('basic preparation follows actual needs without invented clocks or registration prerequisites', function (string $citizenship, string $purpose) {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all());
    $assess = fn (array $values) => (new AssessPerson)->assess(new AssessmentInput(['values' => $values], [], [],
        $catalogue, 'de-nrw-cologne', CarbonImmutable::now()))->toArray();
    $identity = ['citizenship_group' => $citizenship, 'purpose' => $purpose, 'arrival_planned' => false];
    $result = $assess([...$identity, 'registration_status' => 'not_registered', 'tax_id_available' => false,
        'health_coverage_confirmed' => false, 'bank_account_help_needed' => true]);
    $variants = collect($result['processes'])->flatMap(fn ($process) => $process['variants'])->keyBy('id');
    foreach (['core.anmeldung', 'core.steuer_id', 'core.health_insurance', 'core.bank_account'] as $key) {
        expect($variants[$key]['actionable'])->toBeTrue();
        if ($key !== 'core.anmeldung') {
            expect($variants[$key]['deadline']['type'])->toBe('none')
                ->and($variants[$key]['depends_on'])->toBe([]);
        }
    }
    $settled = $assess([...$identity, 'registration_status' => 'registered', 'tax_id_available' => true,
        'health_coverage_confirmed' => true, 'bank_account_help_needed' => false]);
    $actionable = collect($settled['processes'])->flatMap(fn ($process) => $process['variants'])->where('actionable', true)->pluck('id')->all();
    expect($actionable)->not->toContain('core.anmeldung', 'core.steuer_id', 'core.health_insurance', 'core.bank_account');
})->with([['non_eu', 'employment'], ['eu', 'study'], ['non_eu', 'family']]);

test('official verification context is available without choosing a Blue Card route', function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()))->id, null);
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee', 'veedel' => 'Nippes', 'arrival_planned' => false, 'arrival_date' => '2026-07-24',
        'current_residence_title' => 'other', 'case_goal' => 'blue_card',
    ])->assertSessionHasNoErrors();
    $plan = $this->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $verification = collect($plan['guidance'])->firstWhere('id', 'case.bc.verify_status_source');
    expect($verification)->not->toBeNull()
        ->and($verification['missing_facts'])->toBe([])
        ->and(array_column($plan['actions'], 'source_rule_id'))->not->toContain('case.bc.first_application.prepare');
});

test('separation guidance follows the household answer without assuming an unknown answer', function (?bool $household, bool $visible) {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $catalogue = app(CatalogueCompiler::class)->compile(Task::whereNotNull('key')->get()->all());
    $result = (new AssessPerson)->assess(new AssessmentInput(['values' => [
        'purpose' => 'family', 'sponsor' => 'non_eu', 'current_residence_title' => 'family_reunification',
        'marital_household_continues' => $household,
    ]], [], [], $catalogue, 'de-nrw-cologne', CarbonImmutable::now()))->toArray();
    $process = collect($result['processes'])->firstWhere('definition_id', 'residence.family.independent');
    expect($process['variants'] !== [])->toBe($visible);
    if ($household === null) {
        expect($process['variants'][0]['assessment'])->toBe('needs_information')
            ->and(array_column($result['question_dependencies'], 'fact_key'))->toContain('marital_household_continues');
    } elseif ($household === false) {
        expect($process['variants'][0]['assessment'])->toBe('supported_preparation')
            ->and($process['variants'][0]['actionable'])->toBeFalse();
    }
})->with(['together' => [true, false], 'separated' => [false, true], 'unknown' => [null, true]]);
