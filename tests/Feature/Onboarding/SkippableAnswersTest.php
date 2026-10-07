<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Models\Task;
use App\Models\User;

/**
 * All bureaucracy basics are skippable under the accepted replacement contract.
 * Exercise the canonical account API without inventing a branch or reapproving
 * legacy prose. The imported clock is not promoted to a reviewed legal deadline.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile(Task::query()->whereNotNull('key')->get()->all()));
    $store->activate($release->id, null);
});

function anmeldungAction(array $plan): ?array
{
    return collect($plan['actions'])->firstWhere('source_rule_id', 'core.anmeldung');
}

function completeOnboarding(array $answers = [])
{
    $user = User::factory()->create([
        'onboarded_at' => null,
        'email_verified_at' => now(),
    ]);

    $response = test()->actingAs($user)->post('/onboarding/complete', array_replace([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'interests' => [],
    ], $answers));

    return [$user->fresh(), $response];
}

it('completes with only the basic answers supplied', function () {
    [$user, $response] = completeOnboarding();

    $response->assertSessionHasNoErrors();

    expect($user->onboarded_at)->not->toBeNull()
        ->and($user->veedel)->toBe('Altstadt-Nord');
});

it('accepts a skipped basic answer without inventing a fact', function (string $field) {
    [$user, $response] = completeOnboarding([$field => null]);

    $response->assertSessionHasNoErrors();
    expect($user->onboarded_at)->not->toBeNull();
    $fact = ['situation' => 'purpose', 'arrival_planned' => 'arrival_planned'][$field] ?? null;
    if ($fact !== null) {
        expect(app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values'])->not->toHaveKey($fact);
    }
})->with(['situation', 'veedel', 'arrival_planned']);

it('records a skipped answer as unanswered rather than guessing it', function () {
    [$user] = completeOnboarding();

    // entry_mode is the highest-value optional answer — 17 applies_if
    // references — so if a skip ever silently invented one, every branch that
    // reads it would be wrong.
    expect($user->profile_attributes['entry_mode'] ?? null)->toBeNull()
        ->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull();
    $facts = app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values'];
    expect($facts)->not->toHaveKey('entry_mode')->not->toHaveKey('moved_in_at');
});

it('tells the user the Anmeldung clock is missing instead of showing nothing', function () {
    [$user] = completeOnboarding(['registration_status' => 'not_registered']);

    $plan = $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $anmeldung = anmeldungAction($plan);
    expect($anmeldung)->not->toBeNull()
        ->and($anmeldung['dates'][0]['state'])->toBe('date_unknown')
        ->and($anmeldung['dates'][0]['date'])->toBeNull()
        ->and($plan['overview']['question']['fact_key'])->toBe('moved_in_at');
});

it('anchors the imported preparation target to the supplied move-in date', function () {
    [$user] = completeOnboarding([
        'address_registration_status' => 'registrable',
        'registration_status' => 'not_registered',
        'moved_in_at' => now()->subDays(3)->toDateString(),
    ]);

    expect(app(ConfirmedFactView::class)->forCase($user->bureaucracyCase, now()->toDateString())['values']['moved_in_at'] ?? null)
        ->toBe(now()->subDays(3)->toDateString());

    $plan = $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $anmeldung = anmeldungAction($plan);
    expect($anmeldung['dates'][0]['state'])->toBe('dated')
        ->and($anmeldung['dates'][0]['date'])->toBe(now()->addDays(11)->toDateString())
        ->and($anmeldung['dates'][0]['kind'])->toBe('preparation_target');
});

it('keeps undated registration preparation reachable alongside its question', function () {
    [$user] = completeOnboarding(['registration_status' => 'not_registered']);

    $plan = $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $action = anmeldungAction($plan);
    expect($action)->not->toBeNull()
        ->and($action['id'])->toBeIn($plan['progress']['todo']['ids'])
        ->and($plan['overview']['question']['fact_key'])->toBe('moved_in_at');
});

it('asks about actual registration before inventing a need to register again', function () {
    [$user] = completeOnboarding(['moved_in_at' => now()->subDays(3)->toDateString()]);
    $plan = $this->actingAs($user)->getJson('/bureaucracy/v2/plan')->assertSuccessful()->json('plan');
    $guidance = collect($plan['guidance'])->firstWhere('id', 'core.anmeldung');
    expect(anmeldungAction($plan))->toBeNull()
        ->and($guidance['missing_facts'])->toBe(['registration_status']);
});
