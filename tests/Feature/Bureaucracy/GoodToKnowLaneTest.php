<?php

use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\User;

/**
 * Owner: "Why, in the Options you may qualify for section, are we displaying
 * something unrelated?"
 *
 * Because every info-shaped rule fell through to `options`, and that heading
 * promises "possible routes to compare, not eligibility decisions". The card
 * sitting there was `case.bc.verify_status_source` — a UNIVERSAL caveat saying
 * Expadu may not have a reviewed rule for your exact title. It applies to
 * everyone regardless of case, so it is by definition not a route this person
 * might qualify for.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function planSectionsFor(array $facts, array $userOverrides = []): array
{
    $user = User::factory()->create(array_replace([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subYears(3)->toDateString(),
        'german_level' => 'b1',
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ], $userOverrides));

    $case = app(LegacyFactBootstrapper::class)->bootstrap($user);
    app(CaseFactStore::class)->synchronizeConfirmedFacts($user, $facts, 'onboarding');

    $case = $case->fresh();

    return app(CasePlanComposer::class)->compose(
        $case,
        app(CaseMatcher::class)->match($case),
    );
}

function keysIn(array $sections, string $section): array
{
    return collect($sections[$section] ?? [])->pluck('key')->filter()->values()->all();
}

it('files a universal caveat under good to know, not under options', function () {
    $sections = planSectionsFor([
        'citizenship_group' => 'non_eu',
        'purpose' => 'employment',
        'permit_track' => 'blue_card',
        'current_residence_title' => 'blue_card',
    ]);

    expect(keysIn($sections, 'good_to_know'))->toContain('case.bc.verify_status_source')
        ->and(keysIn($sections, 'options'))->not->toContain('case.bc.verify_status_source');
});

it('still calls a real route an option', function () {
    // The spouse §18c route is also `type: info`, but it is case-scoped: it
    // genuinely is a route this person might qualify for, so it stays put.
    $sections = planSectionsFor([
        'citizenship_group' => 'non_eu',
        'purpose' => 'family',
        'current_residence_title' => 'family_reunification',
        'case_goal' => 'renew_current_title',
        'sponsor_current_title' => 'settlement_permit_18c',
        'family_residence_permit_held_since' => now()->subYears(4)->toDateString(),
        'marital_household_continues' => true,
        'weekly_work_hours' => 40,
        'livelihood_secured' => 'yes',
        'housing_sufficient' => 'yes',
        'legal_social_knowledge_proved' => 'yes',
        'german_level' => 'b1',
    ], [
        'situation' => 'family_reunification',
        'bureaucracy_path' => 'family_reunification',
    ]);

    expect(keysIn($sections, 'options'))->toContain('case.family.settlement.spouse_18c_option');
});
