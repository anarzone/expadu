<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Models\Task;
use App\Models\User;

/**
 * Owner review: "The Long game / permanent residency is in two sections — both
 * as something I can apply for next, and under not eligible. And I said in
 * onboarding that I already have settlement residency."
 *
 * The canonical title replaces the old global settled flag. It must not finish
 * unrelated work or suppress a new address process for a permanent resident.
 */
function onboardAs(string $residenceTitle): User
{
    $user = User::factory()->create([
        'onboarded_at' => null,
        'email_verified_at' => now(),
    ]);

    test()->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'entry_mode' => 'has_permit',
        'current_residence_title' => $residenceTitle,
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->subYears(6)->toDateString(),
        'address_registration_status' => 'not_registrable',
        'registration_status' => 'not_registered',
        'interests' => [],
    ])->assertSessionHasNoErrors();

    return $user->fresh();
}

it('records the exact permanent title without a global settled or completion flag', function () {
    $user = onboardAs('settlement_permit_18c');
    $facts = app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString());
    expect($facts['values']['current_residence_title'])->toBe('settlement_permit_18c')
        ->and(data_get($user->profile_attributes, 'settled_at'))->toBeNull()
        ->and($facts['values'])->not->toHaveKey('residence_title_expires_at');
});

it('does not offer Blue Card settlement tracking to a permanent resident but keeps address preparation', function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()))->id, null);
    $holder = onboardAs('settlement_permit_18c');
    $plan = $this->actingAs($holder)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    expect(array_column($plan['guidance'], 'id'))->not->toContain('case.bc.settlement.track_21_months')
        ->and(array_column($plan['actions'], 'source_rule_id'))->toContain('core.anmeldung');

    // Positive control: the actual imported rule is still available for its
    // documented 12-month fixture, conditionally, without an eligibility promise.
    $blueCard = onboardAs('blue_card');
    $case = $blueCard->bureaucracyCase;
    foreach (['blue_card_qualifying_months' => 12, 'german_level' => 'b1'] as $key => $value) {
        app(RecordFactChange::class)->execute($blueCard, $case->person, $key, $value, null, $case->fresh()->fact_version);
    }
    $candidate = $this->actingAs($blueCard)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    expect(collect($candidate['guidance'])->firstWhere('id', 'case.bc.settlement.track_21_months')['assessment'])->toBe('supported_preparation')
        ->and(array_column($candidate['guidance'], 'assessment'))->not->toContain('requirements_met');
});

it('changing an existing title requires an explicit correction rather than silent onboarding overwrite', function () {
    $user = onboardAs('settlement_permit_18c');
    expect(app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values']['current_residence_title'])->toBe('settlement_permit_18c');

    test()->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'entry_mode' => 'has_permit',
        'current_residence_title' => 'standard_work_permit',
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->subYears(6)->toDateString(),
        'address_registration_status' => 'not_registrable',
        'interests' => [],
    ])->assertSessionHasErrors();

    $user->refresh();
    $before = app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString());
    expect($before['values']['current_residence_title'])->toBe('settlement_permit_18c');
    $factId = $before['evidence']['current_residence_title']['fact_id'];
    $this->postJson('/bureaucracy/v2/people/'.$user->bureaucracyCase->person_id.'/facts/'.$factId.'/corrections', [
        'value' => 'standard_work_permit', 'expected_revision' => $before['revision'],
    ])->assertSuccessful();
    expect(data_get($user->profile_attributes, 'settled_at'))->toBeNull()
        ->and(app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values']['current_residence_title'])->toBe('standard_work_permit');
});
