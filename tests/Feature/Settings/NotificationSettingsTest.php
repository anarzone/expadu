<?php

use App\Models\NotificationPreference;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('Settings’ Notifications reads the person’s preferences', function () {
    $user = User::factory()->create();
    $user->notificationPreference()->create([
        'preferences' => ['transit' => false, 'weather' => true],
    ]);

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings')
            ->where('section', 'notifications')
            ->where('notifications.preferences.transit', false)
            ->where('notifications.preferences.weather', true));
});

test('Settings’ Notifications falls back to the defaults', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/notifications')
        ->assertInertia(fn (Assert $page) => $page
            ->where('notifications.preferences', NotificationPreference::defaults())
            ->where('notifications.quiet_from', '22:00')
            ->where('notifications.quiet_to', '06:00')
            ->where('notifications.brief_at', null));
});

test('a guest cannot open Settings’ Notifications', function () {
    $this->get('/settings/notifications')->assertRedirect(route('login'));
});
