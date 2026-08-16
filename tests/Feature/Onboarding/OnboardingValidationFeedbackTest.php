<?php

use App\Models\User;

/**
 * A rejected onboarding submit must come back with errors the wizard can show.
 * QA hit a dead end here: clicking "Open my first plan" with a future arrival
 * date did nothing at all — no error, no hint — and the user could not finish.
 */
it('rejects a future arrival date and flashes an error the UI can render', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'entry_mode' => 'd_visa',
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->addYears(5)->toDateString(),
        'address_registration_status' => 'not_registrable',
        'interests' => [],
    ]);

    $response->assertSessionHasErrors('arrival_date');
    expect($user->fresh()->onboarded_at)->toBeNull();
});

it('accepts the same submission with a past arrival date', function () {
    $user = User::factory()->create([
        'onboarded_at' => null,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'entry_mode' => 'd_visa',
        'veedel' => 'Altstadt-Nord',
        'arrival_planned' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
        'address_registration_status' => 'not_registrable',
        'interests' => [],
    ]);

    $response->assertSessionHasNoErrors();
    expect($user->fresh()->onboarded_at)->not->toBeNull();
});
