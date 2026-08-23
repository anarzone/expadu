<?php

use App\Bureaucracy\Cases\CurrentCasePlan;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyFactConflict;
use App\Models\User;

/**
 * Owner: "It asks me to confirm which one is true. Why does it ask me? I've
 * already answered that question."
 *
 * He had. The conflict was raised at 13:01:17 and the fact it pointed at — a
 * QA persona's `national_d_visa` — was superseded 27 seconds later, but the
 * conflict stayed unresolved. He was being asked to arbitrate between an
 * answer the system had already retired and his real one.
 *
 * A conflict argues about two facts. Retiring one of them retires the
 * argument.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function caseWithConflict(): BureaucracyCase
{
    $user = User::factory()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => now()->subYear()->toDateString(),
        'email_verified_at' => now(),
        'onboarded_at' => now(),
        'profile_attributes' => [],
    ]);

    $case = app(LegacyFactBootstrapper::class)->bootstrap($user);
    $store = app(CaseFactStore::class);

    $store->synchronizeConfirmedFacts($user, [
        'citizenship_group' => 'non_eu',
        'purpose' => 'employment',
        'current_residence_title' => 'national_d_visa',
    ], 'qa_scenario:probe');

    $candidate = $store->recordCandidate(
        $case->fresh(), 'current_residence_title', 'family_reunification',
        'structured_interview', 'probe',
    );
    $store->confirmCandidate($candidate);

    return $case->fresh();
}

it('raises a conflict while both answers still stand', function () {
    $case = caseWithConflict();

    expect(BureaucracyFactConflict::query()->where('case_id', $case->id)->actionable()->exists())
        ->toBeTrue();
});

it('drops the question once the answer it argued about is retired', function () {
    $case = caseWithConflict();

    // Whatever retires the confirmed side — a persona replacing its own seed,
    // a later answer — the argument goes with it.
    $confirmed = BureaucracyCaseFact::query()
        ->where('case_id', $case->id)
        ->where('key', 'current_residence_title')
        ->where('state', 'confirmed')
        ->firstOrFail();

    app(CaseFactStore::class)->synchronizeConfirmedFacts(
        $case->user()->firstOrFail(),
        ['current_residence_title' => 'blue_card'],
        'onboarding',
    );

    expect($confirmed->fresh()->state)->toBe('superseded')
        ->and(BureaucracyFactConflict::query()->where('case_id', $case->id)->actionable()->exists())
        ->toBeFalse();
});

it('never puts a retired answer in front of the user', function () {
    $case = caseWithConflict();

    // Retire the confirmed side directly, leaving the conflict row behind the
    // way the live data did.
    BureaucracyCaseFact::query()
        ->where('case_id', $case->id)
        ->where('key', 'current_residence_title')
        ->where('state', 'confirmed')
        ->update(['state' => 'superseded', 'superseded_at' => now()]);

    $plan = app(CurrentCasePlan::class)->for($case->user()->firstOrFail());

    expect($plan['active_conflict'])->toBeNull()
        ->and($plan['coverage_state'])->not->toBe('conflict');
});
