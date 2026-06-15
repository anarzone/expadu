<?php

use App\Composer\IntentWeights;
use App\Models\User;

test('a post-trip thumbs-up is recorded and lifts that category in intent weights', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/api/track', [
        'event_type' => 'post_trip_thumbs_up',
        'payload' => ['category' => 'cafe', 'veedel' => 'Ehrenfeld'],
    ])->assertNoContent();

    $weights = app(IntentWeights::class)->for($user->fresh());

    expect($weights['cafe|Ehrenfeld'] ?? 0.0)->toBeGreaterThan(0.0);
});

test('a post-trip thumbs-down never boosts the category', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson('/api/track', [
        'event_type' => 'post_trip_thumbs_down',
        'payload' => ['category' => 'bar', 'veedel' => 'Ehrenfeld'],
    ])->assertNoContent();

    $weights = app(IntentWeights::class)->for($user->fresh());

    // The negative signal clamps to 0 — it pulls the category down, never up.
    expect($weights['bar|Ehrenfeld'] ?? 0.0)->toBe(0.0);
});
