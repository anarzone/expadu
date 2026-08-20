<?php

use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\PermanentResidencyEligibility;
use App\Models\Task;
use App\Models\User;
use App\Profile\Applicability;
use App\Profile\ProfileEngine;

/**
 * Owner review: "The Long game / permanent residency is in two sections — both
 * as something I can apply for next, and under not eligible. And I said in
 * onboarding that I already have settlement residency."
 *
 * Cause: two unconnected notions of already-holding-PR. `current_residence_title`
 * is a case fact written by onboarding; `settled_at` is a profile attribute set
 * only by the separate "I'm settled" action. Nothing joined them, so declaring a
 * settlement permit left the app still offering permanent residency.
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
        'interests' => [],
    ])->assertSessionHasNoErrors();

    return $user->fresh();
}

it('treats a declared settlement permit as being settled', function () {
    $user = onboardAs('settlement_permit_18c');

    expect(data_get($user->profile_attributes, 'settled_at'))->not->toBeNull();
});

it('stops offering permanent residency to someone who already holds it', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();

    $engine = app(ProfileEngine::class);
    $paths = app(PathGenerator::class);
    $longGame = Task::query()->where('key', 'shared.long_game')->firstOrFail();

    $settled = $engine->build(onboardAs('settlement_permit_18c'));
    $notSettled = $engine->build(onboardAs('standard_work_permit'));

    // The eligibility nudge and the info card must agree with the user.
    expect(app(PermanentResidencyEligibility::class)->for($settled))->toBeNull()
        ->and($paths->applicability($longGame, $settled))->not->toBe(Applicability::Yes);

    // Someone who does NOT hold it still gets the guidance.
    expect($paths->applicability($longGame, $notSettled))->toBe(Applicability::Yes);
});

it('clears the settled claim when the answer changes', function () {
    $user = onboardAs('settlement_permit_18c');
    expect(data_get($user->profile_attributes, 'settled_at'))->not->toBeNull();

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
    ])->assertSessionHasNoErrors();

    expect(data_get($user->fresh()->profile_attributes, 'settled_at'))->toBeNull();
});
