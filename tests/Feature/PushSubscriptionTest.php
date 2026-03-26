<?php

use App\Models\User;

test('guest cannot subscribe to push notifications', function () {
    $this->postJson(route('push.subscribe'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
        'keys' => [
            'p256dh' => 'test-p256dh-key',
            'auth' => 'test-auth-key',
        ],
    ])->assertUnauthorized();
});

test('guest cannot unsubscribe from push notifications', function () {
    $this->postJson(route('push.unsubscribe'), [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
    ])->assertUnauthorized();
});

test('authenticated user can subscribe to push notifications', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
        ])
        ->assertCreated()
        ->assertJson(['message' => 'Subscription saved.']);

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_id' => $user->id,
        'subscribable_type' => User::class,
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint',
    ]);
});

test('authenticated user can unsubscribe from push notifications', function () {
    $user = User::factory()->onboarded()->create();
    $endpoint = 'https://fcm.googleapis.com/fcm/send/test-endpoint';

    $user->updatePushSubscription($endpoint, 'test-p256dh', 'test-auth');

    $this->actingAs($user)
        ->postJson(route('push.unsubscribe'), [
            'endpoint' => $endpoint,
        ])
        ->assertOk()
        ->assertJson(['message' => 'Subscription removed.']);

    $this->assertDatabaseMissing('push_subscriptions', [
        'subscribable_id' => $user->id,
        'endpoint' => $endpoint,
    ]);
});

test('subscribe validates required fields', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
});

test('subscribe validates endpoint is a url', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('push.subscribe'), [
            'endpoint' => 'not-a-url',
            'keys' => [
                'p256dh' => 'test-p256dh-key',
                'auth' => 'test-auth-key',
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);
});

test('unsubscribe validates endpoint is required', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson(route('push.unsubscribe'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['endpoint']);
});
