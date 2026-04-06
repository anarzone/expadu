<?php

use App\Models\User;
use App\Models\UserSetting;

test('user settings show returns defaults when no settings exist', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->getJson('/user-settings')
        ->assertSuccessful()
        ->assertJson([
            'settings' => UserSetting::defaults(),
        ]);
});

test('user settings can be updated partially', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->putJson('/user-settings', [
            'settings' => ['share_location' => false],
        ])
        ->assertSuccessful()
        ->assertJson([
            'settings' => [
                'share_location' => false,
                'personalised_content' => true,
                'share_anonymised' => true,
                'profile_visibility' => 'partners',
            ],
        ]);

    expect($user->fresh()->userSetting->settings['share_location'])->toBeFalse();
});

test('user settings persist across requests', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->putJson('/user-settings', [
            'settings' => ['personalised_content' => false],
        ])
        ->assertSuccessful();

    // Fresh user instance to verify DB persistence
    $this->actingAs($user->fresh())
        ->getJson('/user-settings')
        ->assertJson([
            'settings' => ['personalised_content' => false],
        ]);
});

test('user settings validates input', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->putJson('/user-settings', [
            'settings' => ['profile_visibility' => 'invalid_value'],
        ])
        ->assertUnprocessable();
});

test('user setting helper returns correct values', function () {
    $user = User::factory()->onboarded()->create();

    // Defaults
    expect($user->setting('share_location'))->toBeTrue();
    expect($user->setting('personalised_content'))->toBeTrue();

    // After update
    $user->userSetting()->create([
        'settings' => ['share_location' => false, 'personalised_content' => true, 'share_anonymised' => true, 'profile_visibility' => 'partners'],
    ]);
    $user->refresh();

    expect($user->setting('share_location'))->toBeFalse();
    expect($user->setting('personalised_content'))->toBeTrue();
});

test('notification preferences shared prop is available', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('profile'));
    $response->assertOk();

    $props = $response->original->getData()['page']['props'];
    expect($props)->toHaveKey('notificationPreferences');
    expect($props)->toHaveKey('userSettings');
});

test('unauthenticated user cannot access settings', function () {
    $this->getJson('/user-settings')->assertUnauthorized();
    $this->putJson('/user-settings', ['settings' => []])->assertUnauthorized();
});
