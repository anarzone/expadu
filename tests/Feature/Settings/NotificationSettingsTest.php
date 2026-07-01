<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('notification settings page renders with the users preferences', function () {
    $user = User::factory()->create();
    $user->notificationPreference()->create([
        'preferences' => ['transit' => false, 'weather' => true],
    ]);

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/notifications')
            ->where('preferences.transit', false)
            ->where('preferences.weather', true),
        );
});

test('notification settings falls back to defaults when the user has none', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('notifications.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('preferences', NotificationPreference::defaults()),
        );
});

test('a guest cannot open the notification settings page', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));
});
