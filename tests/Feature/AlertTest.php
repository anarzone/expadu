<?php

use App\Models\Alert;
use App\Models\User;

test('alerts page renders with alerts', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->count(3)->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $response = $this->get(route('alerts'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('alerts')
        ->has('alerts.data', 3)
        ->has('unreadCount')
    );
});

test('alerts can be filtered by type', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->create(['user_id' => $user->id, 'type' => 'system']);
    Alert::factory()->create(['user_id' => $user->id, 'type' => 'social']);
    $this->actingAs($user);

    $response = $this->get(route('alerts', ['tab' => 'system']));

    $response->assertInertia(fn ($page) => $page->has('alerts.data', 1));
});

test('user can mark an alert as read', function () {
    $user = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $user->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read', $alert));

    expect($alert->fresh()->read_at)->not->toBeNull();
});

test('user can mark all alerts as read', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->count(3)->create(['user_id' => $user->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read-all'));

    expect($user->alerts()->whereNull('read_at')->count())->toBe(0);
});

test('user cannot mark another users alert as read', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $other->id, 'read_at' => null]);
    $this->actingAs($user);

    $this->post(route('alerts.read', $alert));

    expect($alert->fresh()->read_at)->toBeNull();
});

test('user can dismiss an alert', function () {
    $user = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $user->id]);
    $this->actingAs($user);

    $this->postJson(route('alerts.dismiss', $alert));

    expect($alert->fresh())
        ->dismissed_at->not->toBeNull()
        ->read_at->not->toBeNull();
});

test('dismissed alerts are excluded from the alerts page', function () {
    $user = User::factory()->onboarded()->create();
    Alert::factory()->create(['user_id' => $user->id, 'dismissed_at' => now()]);
    Alert::factory()->create(['user_id' => $user->id, 'dismissed_at' => null]);
    $this->actingAs($user);

    $this->get(route('alerts'))
        ->assertInertia(fn ($page) => $page->has('alerts.data', 1));
});

test('user cannot dismiss another users alert', function () {
    $user = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $alert = Alert::factory()->create(['user_id' => $other->id]);
    $this->actingAs($user);

    $this->postJson(route('alerts.dismiss', $alert));

    expect($alert->fresh()->dismissed_at)->toBeNull();
});
