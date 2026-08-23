<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\Facts\LegacyFactBootstrapper;
use App\Models\BureaucracyFactConflict;
use App\Models\User;

/**
 * Owner hit "Something blocked the response — an ad blocker or browser
 * extension may be interfering" on the Bureaucracy page while a fact conflict
 * was open. That toast is Inertia's `invalid` catch-all: it fires on ANY
 * non-Inertia response, so it blames the browser for what is usually a server
 * error or a redirect off the Inertia protocol.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function conflictedUser(): User
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
    $store = app(CaseFactStore::class);

    $store->synchronizeConfirmedFacts($user, [
        'citizenship_group' => 'non_eu',
        'purpose' => 'family',
        'current_residence_title' => 'national_d_visa',
    ], 'onboarding');

    $candidate = $store->recordCandidate(
        $case->fresh(), 'current_residence_title', 'family_reunification',
        'structured_interview', 'probe',
    );
    $store->confirmCandidate($candidate);

    return $user->fresh();
}

it('resolves a conflict with a redirect Inertia understands', function (string $choice) {
    $user = conflictedUser();
    $conflict = BureaucracyFactConflict::query()->where('status', 'unresolved')->latest('id')->firstOrFail();

    $response = $this->actingAs($user)
        ->from('/bureaucracy')
        ->patch("/bureaucracy/case/conflicts/{$conflict->id}", ['choice' => $choice], [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => '',
        ]);

    // Anything that is not a redirect or an Inertia payload trips the
    // "an ad blocker is interfering" toast, which blames the wrong thing.
    expect($response->status())->toBeIn([200, 302, 303, 409]);
    $response->assertHeaderMissing('X-Inertia-Location');

    expect($conflict->fresh()->status)->not->toBe('unresolved');
})->with(['candidate', 'existing']);
