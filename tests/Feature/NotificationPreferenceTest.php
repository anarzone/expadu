<?php

use App\Models\NotificationPreference;
use App\Models\User;

it('returns default preferences for user without saved preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))
        ->assertSuccessful()
        ->assertJson([
            'preferences' => NotificationPreference::defaults(),
        ]);
});

it('returns saved preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    NotificationPreference::create([
        'user_id' => $user->id,
        'preferences' => ['transit' => false, 'burgeramt' => true],
    ]);
    $this->actingAs($user);

    $this->getJson(route('notification-preferences.show'))
        ->assertSuccessful()
        ->assertJson([
            'preferences' => ['transit' => false, 'burgeramt' => true],
        ]);
});

it('updates preferences', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['transit' => false, 'events' => true],
    ])->assertSuccessful();

    expect($user->fresh()->notificationPreference->preferences)
        ->toMatchArray(['transit' => false, 'events' => true]);
});

it('creates preference on first update', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $this->actingAs($user);

    expect($user->notificationPreference)->toBeNull();

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['digest' => true],
    ])->assertSuccessful();

    expect($user->fresh()->notificationPreference)->not->toBeNull();
});

it('wantsNotification returns correct value', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    NotificationPreference::create([
        'user_id' => $user->id,
        'preferences' => ['transit' => false, 'burgeramt' => true],
    ]);

    expect($user->wantsNotification('transit'))->toBeFalse();
    expect($user->wantsNotification('burgeramt'))->toBeTrue();
    expect($user->wantsNotification('unknown'))->toBeTrue(); // defaults to true
});

it('requires authentication', function () {
    $this->getJson(route('notification-preferences.show'))
        ->assertUnauthorized();

    $this->putJson(route('notification-preferences.update'), [
        'preferences' => ['transit' => false],
    ])->assertUnauthorized();
});
