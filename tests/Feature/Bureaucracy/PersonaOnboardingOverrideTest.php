<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\QA\ScenarioFactSynchronizer;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyFactConflict;
use App\Models\User;
use App\Onboarding\ApplyOnboardingAnswers;

/**
 * Owner: "Why does it say two answers do not match? I've entered my answers,
 * but it says it is not matching."
 *
 * He had entered them once. The QA persona switcher had seeded facts under
 * `qa_scenario:*` first, and a fact arriving from a DIFFERENT source is what
 * the conflict machinery exists to catch — so his own onboarding answers came
 * back as a disagreement with a synthetic persona.
 *
 * ApplyOnboardingAnswers already declares the intended precedence: it clears
 * the `qa_persona` badge because "hand-answered onboarding replaces whatever
 * persona the QA switcher last applied". It just never retired the persona's
 * facts, so only half of that was true.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function onboardedAfter(?array $persona): User
{
    $user = User::factory()->create([
        'onboarded_at' => null,
        'email_verified_at' => now(),
        'profile_attributes' => [],
    ]);

    if ($persona !== null) {
        app(ScenarioFactSynchronizer::class)->sync($user, $persona);
    }

    app(ApplyOnboardingAnswers::class)->execute($user->fresh(), [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => false,
        'arrival_date' => now()->subYear()->toDateString(),
        'entry_mode' => 'has_permit',
        'current_residence_title' => 'family_reunification',
        'interests' => [],
    ]);

    return $user->fresh();
}

function unresolvedFactKeys(User $user): array
{
    return BureaucracyFactConflict::query()
        ->whereIn('case_id', BureaucracyCase::query()->where('user_id', $user->getKey())->pluck('id'))
        ->where('status', 'unresolved')
        ->pluck('fact_key')
        ->sort()
        ->values()
        ->all();
}

it('does not ask the user to reconcile their answers with a QA persona', function () {
    $persona = collect(BureaucracyPersonas::caseScenarios())->firstOrFail();

    expect(unresolvedFactKeys(onboardedAfter($persona)))->toBe([]);
});

it('leaves a persona-free onboarding untouched', function () {
    expect(unresolvedFactKeys(onboardedAfter(null)))->toBe([]);
});

it('still flags a genuine disagreement between two different real sources', function () {
    // The machinery must keep working where it belongs: an answer given to the
    // case assistant that contradicts the onboarding answer is a real conflict
    // the user has to settle.
    $user = onboardedAfter(null);
    $case = BureaucracyCase::query()->where('user_id', $user->getKey())->latest('id')->firstOrFail();

    $store = app(CaseFactStore::class);
    $candidate = $store->recordCandidate(
        $case, 'current_residence_title', 'blue_card', 'structured_interview', 'question:1',
    );
    $store->confirmCandidate($candidate);

    expect(unresolvedFactKeys($user->fresh()))->toContain('current_residence_title');
});
