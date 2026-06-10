<?php

use App\Models\User;

test('onboarding page renders for non-onboarded user', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('onboarding'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('veedels'));
});

test('onboarded user accessing onboarding is not redirected away', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('onboarding'));
    $response->assertOk();
});

test('onboarding can be completed with valid data', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'non_eu_employee',
        'veedel' => 'Ehrenfeld',
        'german_level' => 'a2',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertRedirect(route('dashboard'));

    $user->refresh();
    expect($user->situation->value)->toBe('non_eu_employee');
    expect($user->veedel)->toBe('Ehrenfeld');
    expect($user->city)->toBe('Köln');
    expect($user->german_level->value)->toBe('a2');
    expect($user->onboarded_at)->not->toBeNull();
});

test('ambiguous situations require the EU question', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'veedel' => 'Sülz',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertSessionHasErrors('is_eu');
});

test('employee situations do not require the EU question', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Nippes',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertRedirect(route('dashboard'));
});

test('german level is optional', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => true,
        'veedel' => 'Deutz',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertRedirect(route('dashboard'));
});

test('onboarding fails with invalid situation', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'invalid_value',
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertSessionHasErrors('situation');
});

test('onboarding fails with unknown veedel', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'eu_employee',
        'veedel' => 'Atlantis',
        'arrival_date' => '2026-01-15',
    ]);

    $response->assertSessionHasErrors('veedel');
});

test('onboarding fails without required fields', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), []);

    $response->assertSessionHasErrors(['situation', 'veedel', 'arrival_date']);
});

test('onboarding fails with future arrival date', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->post(route('onboarding.complete'), [
        'situation' => 'student',
        'is_eu' => false,
        'veedel' => 'Ehrenfeld',
        'arrival_date' => '2030-01-01',
    ]);

    $response->assertSessionHasErrors('arrival_date');
});

test('non-onboarded user is redirected from protected pages', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));
    $response->assertRedirect(route('onboarding'));
});

test('onboarded user can access protected pages', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));
    $response->assertOk();
});
