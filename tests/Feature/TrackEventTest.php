<?php

use App\Models\User;
use App\Models\UserEvent;

test('tracking endpoint records a user event', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
        'payload' => ['page' => '/dashboard'],
    ]);

    $response->assertNoContent();

    $this->assertDatabaseHas('user_events', [
        'user_id' => $user->id,
        'event_type' => 'page_viewed',
    ]);

    $event = UserEvent::where('user_id', $user->id)->first();
    expect($event->payload)->toBe(['page' => '/dashboard']);
});

test('tracking endpoint works without payload', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'onboarding_complete',
    ]);

    $response->assertNoContent();

    $this->assertDatabaseHas('user_events', [
        'user_id' => $user->id,
        'event_type' => 'onboarding_complete',
    ]);
});

test('tracking endpoint validates event_type is required', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('event_type');
});

test('tracking endpoint validates event_type max length', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => str_repeat('a', 101),
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('event_type');
});

test('tracking endpoint validates payload must be array', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->postJson('/api/track', [
        'event_type' => 'test_event',
        'payload' => 'not_an_array',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('payload');
});

test('tracking endpoint requires authentication', function () {
    $response = $this->postJson('/api/track', [
        'event_type' => 'page_viewed',
    ]);

    $response->assertUnauthorized();
});
