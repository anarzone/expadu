<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\User;

/**
 * Germany has more than one permanent residence permit. §9 is the ordinary
 * route; §18c is the skilled-worker / Blue Card one. Both are called
 * Niederlassungserlaubnis, and onboarding offered a single "Settlement permit"
 * button that stored §18c — so a §9 holder was recorded as §18c.
 *
 * That is not cosmetic: the spouse-settlement route
 * (case.family.settlement.spouse_18c_option) is gated on the sponsor holding
 * §18c specifically. Recording §9 as §18c would offer a route the person may
 * have no claim to.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function matchedKeysForSponsor(string $sponsorTitle): array
{
    $user = User::factory()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'bureaucracy_path' => 'family_reunification',
        'veedel' => 'Altstadt-Nord',
        'arrival_date' => now()->subYears(4)->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
    ]);

    // `sponsor` is derived by ProfileEngine from bureaucracy_path, so seed the
    // case from the profile first and then add the facts a user actually answers.
    app(LegacyFactBootstrapper::class)->bootstrap($user);

    // Everything the §18c spouse route requires, so the ONLY variable under
    // test is which permanent residence permit the sponsor holds.
    $case = app(CaseFactStore::class)->synchronizeConfirmedFacts($user, [
        'sponsor_current_title' => $sponsorTitle,
        'current_residence_title' => 'family_reunification',
        'case_goal' => 'settlement_permit',
        'family_residence_permit_held_since' => now()->subYears(4)->toDateString(),
        'marital_household_continues' => true,
        'weekly_work_hours' => 30,
        'livelihood_secured' => 'yes',
        'housing_sufficient' => 'yes',
        'legal_social_knowledge_proved' => 'yes',
        'german_level' => 'b1',
    ], 'test');

    return app(CaseMatcher::class)->match($case)->matchedRuleKeys;
}

it('offers the spouse-of-18c settlement route only to an actual 18c sponsor', function () {
    expect(matchedKeysForSponsor('settlement_permit_18c'))
        ->toContain('case.family.settlement.spouse_18c_option');

    // The ordinary §9 route must NOT unlock an §18c-specific option.
    expect(matchedKeysForSponsor('settlement_permit_9'))
        ->not->toContain('case.family.settlement.spouse_18c_option');
});

it('accepts both permanent residence values through onboarding', function (string $title) {
    $user = User::factory()->create(['onboarded_at' => null, 'email_verified_at' => now()]);

    $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'entry_mode' => 'has_permit',
        'current_residence_title' => $title,
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->subYears(6)->toDateString(),
        'address_registration_status' => 'not_registrable',
        'interests' => [],
    ])->assertSessionHasNoErrors();

    // Either permanent residence permit means the holder is settled.
    expect(data_get($user->fresh()->profile_attributes, 'settled_at'))->not->toBeNull();
})->with(['settlement_permit_9', 'settlement_permit_18c']);
