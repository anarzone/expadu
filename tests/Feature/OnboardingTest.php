<?php

use App\Models\Task;
use App\Models\User;

test('onboarding page renders for non-onboarded user', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('onboarding'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->has('veedels')->has('taskPreviews'));
});

test('task previews show the first actionable tasks per branch, EU-filtered', function () {
    Task::factory()->create([
        'key' => 'ob.anmeldung',
        'title' => 'Register your address (Anmeldung)',
        'situation' => ['student'],
        'phase' => 'arrival',
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 14,
        'booking_service_key' => 'anmeldung',
    ]);
    Task::factory()->create([
        'key' => 'ob.permit',
        'title' => 'Student residence permit',
        'situation' => ['student'],
        'phase' => 'first_30_days',
        'eu_filter' => 'non_eu_only',
    ]);
    Task::factory()->create([
        'key' => 'ob.info',
        'title' => 'Church tax',
        'type' => 'info',
        'situation' => ['student'],
        'phase' => 'arrival',
    ]);

    $this->actingAs(User::factory()->notOnboarded()->create());
    $response = $this->get(route('onboarding'));

    $response->assertInertia(function ($page) {
        $student = $page->toArray()['props']['taskPreviews']['student'];

        // Anmeldung first (arrival phase, tightest deadline), with the booking hint
        expect($student['eu'][0]['title'])->toBe('Register your address (Anmeldung)');
        expect($student['eu'][0]['meta'])->toBe('Bürgeramt · walk-in Mon & Wed');
        expect($student['eu'][0]['deadline_days'])->toBe(14);

        $titles = array_column($student['eu'], 'title');
        expect($titles)->not->toContain('Student residence permit'); // non_eu_only
        expect($titles)->not->toContain('Church tax'); // info cards excluded

        expect(array_column($student['non_eu'], 'title'))->toContain('Student residence permit');

        return true;
    });
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
