<?php

use App\Models\User;

/**
 * Owner review of step 3: "if the user still didn't arrive, why do we ask
 * where do you live? We cover the people which didn't come here yet."
 *
 * The screen asked the address questions first and the arrival question last,
 * so the answer that decides whether the address questions mean anything came
 * after them. Worse, switching to "Still planning" did not clear what had
 * already been answered.
 */
it('refuses a move-in date from someone who has not arrived', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'email_verified_at' => now()]);

    $response = $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => true,
        'address_registration_status' => 'registrable',
        'moved_in_at' => now()->subDays(5)->toDateString(),
        'interests' => [],
    ]);

    $response->assertSessionHasErrors('moved_in_at');
    expect($user->fresh()->onboarded_at)->toBeNull();
});

it('accepts a planning arrival with no address answers at all', function () {
    $user = User::factory()->create(['onboarded_at' => null, 'email_verified_at' => now()]);

    $response = $this->actingAs($user)->post('/onboarding/complete', [
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_planned' => true,
        'interests' => [],
    ]);

    $response->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->onboarded_at)->not->toBeNull()
        ->and($user->arrival_date)->toBeNull()
        ->and($user->profile_attributes['moved_in_at'] ?? null)->toBeNull()
        ->and($user->profile_attributes['housing_status'] ?? null)->toBeNull();
});
