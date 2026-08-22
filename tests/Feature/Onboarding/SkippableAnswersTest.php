<?php

use App\Models\User;

/**
 * Onboarding asked for seventeen things and hard-required eight of them. Only
 * three break a concrete feature if missing: `situation` picks the branch,
 * `veedel` drives places, commute and alerts, and the arrival answer anchors
 * every `days_since_arrival` deadline.
 *
 * The rest are now optional, which is only honest because they can be finished
 * later: registered facts come back through PendingAnswers on the Bureaucracy
 * page, and `moved_in_at` through the Anmeldung card's own prompt.
 */
beforeEach(function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
});

function anmeldungCard($response): ?array
{
    // `tasks` is keyed by bucket (active / upcoming / completed / …), so the
    // card can sit under any of them depending on its deadline state.
    return collect($response->viewData('page')['props']['tasks'] ?? [])
        ->flatMap(fn (mixed $bucket): array => is_array($bucket) ? $bucket : [])
        ->firstWhere('key', 'nee.anmeldung');
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

it('completes on the three required answers alone', function () {
    [$user, $response] = completeOnboarding();

    $response->assertSessionHasNoErrors();

    expect($user->onboarded_at)->not->toBeNull()
        ->and($user->veedel)->toBe('Altstadt-Nord');
});

it('still refuses a submission missing one of the three', function (string $field) {
    [$user, $response] = completeOnboarding([$field => null]);

    $response->assertSessionHasErrors($field);
    expect($user->onboarded_at)->toBeNull();
})->with(['situation', 'veedel', 'arrival_planned']);

it('records a skipped answer as unanswered rather than guessing it', function () {
    [$user] = completeOnboarding();

    // entry_mode is the highest-value optional answer — 17 applies_if
    // references — so if a skip ever silently invented one, every branch that
    // reads it would be wrong.
    expect($user->profile_attributes['entry_mode'] ?? null)->toBeNull()
        ->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull();
});

it('tells the user the Anmeldung clock is missing instead of showing nothing', function () {
    [$user] = completeOnboarding();

    $response = $this->actingAs($user)->get('/bureaucracy');
    $response->assertSuccessful();

    $anmeldung = anmeldungCard($response);

    // §17 BMG gives two weeks and §54 makes missing it finable, so an absent
    // countdown has to be stated, not left blank.
    expect($anmeldung)->not->toBeNull()
        ->and($anmeldung['deadline_tier'])->toBe('needs_answer')
        ->and($anmeldung['deadline_note'])->toContain('move-in date')
        ->and($anmeldung['deadline_action'])->toBe('moved_in');
});

it('keeps the real deadline when the move-in date is given', function () {
    [$user] = completeOnboarding([
        'address_registration_status' => 'registrable',
        'moved_in_at' => now()->subDays(3)->toDateString(),
    ]);

    expect($user->profile_attributes['moved_in_at'] ?? null)->not->toBeNull();

    $response = $this->actingAs($user)->get('/bureaucracy');
    $anmeldung = anmeldungCard($response);

    expect($anmeldung['deadline_tier'])->not->toBe('needs_answer')
        ->and($anmeldung['days_remaining'])->toBe(11);
});

it('keeps the undated Anmeldung in the attention lane', function () {
    // It is the two-week window from §17 BMG with an unknown start date. Filed
    // under "upcoming" it would be the one card whose clock most needs starting,
    // shown as if it could wait.
    [$user] = completeOnboarding();

    $response = $this->actingAs($user)->get('/bureaucracy');
    $active = collect($response->viewData('page')['props']['tasks']['active'] ?? []);

    expect($active->pluck('key'))->toContain('nee.anmeldung');
});
