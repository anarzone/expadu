<?php

use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\User;

/**
 * Owner testing a family-reunification renewal: the same question appeared
 * three times on one screen — once in the assistant and twice more under
 * "Information we still need".
 *
 * The composer emitted one card per blocked RULE. Two rules
 * (case.family.renew.continuing_household and
 * case.family.independent_after_separation) both hinge on whether the
 * household continues, so each printed the identical question under a heading
 * — "A possible step needs more information" — that named neither step.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function familyRenewalPlan(): array
{
    $user = User::factory()->create([
        'situation' => 'family_reunification',
        'is_eu' => false,
        'bureaucracy_path' => 'family_reunification',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subYears(2)->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ]);

    $case = app(LegacyFactBootstrapper::class)->bootstrap($user);

    app(CaseFactStore::class)->synchronizeConfirmedFacts($user, [
        'citizenship_group' => 'non_eu',
        'purpose' => 'family',
        'current_residence_title' => 'family_reunification',
        'sponsor_current_title' => 'blue_card',
        'case_goal' => 'renew_current_title',
    ], 'onboarding');

    return app(CurrentCasePlan::class)->for($user->fresh());
}

it('asks each question once, however many steps are waiting on it', function () {
    $cards = collect(familyRenewalPlan()['sections']['information_needed'] ?? []);

    $questions = $cards
        ->flatMap(fn (array $card): array => array_column($card['questions'] ?? [], 'question'))
        ->all();

    expect($questions)->toBe(array_values(array_unique($questions)))
        ->and($cards->pluck('fact_key')->all())
        ->toBe(array_values(array_unique($cards->pluck('fact_key')->all())));
});

it('names the steps an answer would unblock', function () {
    $cards = collect(familyRenewalPlan()['sections']['information_needed'] ?? []);

    expect($cards)->not->toBeEmpty();

    // The household question gates two rules; the card has to say so rather
    // than calling both of them "a possible step".
    $household = $cards->firstWhere('fact_key', 'marital_household_continues');

    expect($household)->not->toBeNull()
        ->and($household['unlocks'])->toHaveCount(2)
        ->and($household['unlocks'][0])->not->toBeEmpty();
});
