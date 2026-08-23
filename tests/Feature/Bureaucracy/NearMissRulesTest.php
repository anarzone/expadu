<?php

use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\Cases\NearMissRules;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\BureaucracyCase;
use App\Models\User;

/**
 * Owner testing his wife's case: she joined him on family reunification while
 * he holds a Blue Card, and "Do now" offered only the renewal. Nothing said
 * why the permanent-residence route for spouses was absent.
 *
 * It was absent correctly. §9 Absatz 3a reads "Dem Ehegatten eines Ausländers,
 * der eine Niederlassungserlaubnis nach § 18c besitzt" — the route needs the
 * sponsor to hold §18c, and a Blue Card is not one. But a rule hidden without
 * a reason reads as a rule that does not exist.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function spouseCase(string $sponsorTitle): BureaucracyCase
{
    $user = User::factory()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'bureaucracy_path' => 'family_reunification',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subYears(4)->toDateString(),
        'german_level' => 'b1',
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ]);

    $case = app(LegacyFactBootstrapper::class)->bootstrap($user);

    app(CaseFactStore::class)->synchronizeConfirmedFacts($user, [
        'citizenship_group' => 'non_eu',
        'purpose' => 'family',
        'current_residence_title' => 'family_reunification',
        'case_goal' => 'renew_current_title',
        'sponsor_current_title' => $sponsorTitle,
        'family_residence_permit_held_since' => now()->subYears(4)->toDateString(),
        'marital_household_continues' => true,
        'weekly_work_hours' => 40,
        'livelihood_secured' => 'yes',
        'housing_sufficient' => 'yes',
        'legal_social_knowledge_proved' => 'yes',
        'german_level' => 'b1',
    ], 'onboarding');

    return $case->fresh();
}

it('names the one thing standing between a spouse and the §18c route', function () {
    $rows = app(NearMissRules::class)->forCase(spouseCase('blue_card'));

    $spouseRoute = collect($rows)->firstWhere('key', 'case.family.settlement.spouse_18c_option');

    expect($spouseRoute)->not->toBeNull()
        ->and($spouseRoute['opens_when'])->toContain('§18c')
        ->and($spouseRoute['opens_when'])->toContain('spouse');
});

it('drops the notice once the route actually applies', function () {
    $rows = app(NearMissRules::class)->forCase(spouseCase('settlement_permit_18c'));

    expect(collect($rows)->pluck('key'))
        ->not->toContain('case.family.settlement.spouse_18c_option');

    // ...because it is now a real option rather than a locked one.
    $plan = app(CurrentCasePlan::class)->for(
        User::query()->whereKey(spouseCase('settlement_permit_18c')->user_id)->firstOrFail()
    );

    expect(collect($plan['sections']['options'])->pluck('key'))
        ->toContain('case.family.settlement.spouse_18c_option');
});

it('never phrases a route as opening on a separation', function () {
    // case.family.independent_after_separation needs the household NOT to
    // continue. "Opens when you no longer live together" is a life event, not
    // a next step, and must never be surfaced as an opportunity.
    $rows = app(NearMissRules::class)->forCase(spouseCase('blue_card'));

    expect(collect($rows)->pluck('key'))
        ->not->toContain('case.family.independent_after_separation');
});

it('stays silent about branches the user could never switch into', function () {
    // The core spine is gated on `purpose` being digital_nomad or other, which
    // is a branch predicate compiled from the rule's situation header — not
    // something anyone chooses to become.
    $rows = app(NearMissRules::class)->forCase(spouseCase('blue_card'));

    foreach ($rows as $row) {
        expect($row['opens_when'])->not->toContain('purpose');
    }
});
