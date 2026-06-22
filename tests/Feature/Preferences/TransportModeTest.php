<?php

use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->actingAs($this->user);
});

test('persists a valid transport mode', function () {
    $this->postJson('/api/preferences/transport-mode', ['mode' => 'bike'])
        ->assertOk()
        ->assertJson(['transport_mode' => 'bike']);

    expect($this->user->fresh()->transport_mode?->value)->toBe('bike');
});

test('clears the mode back to fastest-realistic when null', function () {
    $this->user->update(['transport_mode' => 'walk']);

    $this->postJson('/api/preferences/transport-mode', ['mode' => null])
        ->assertOk()
        ->assertJson(['transport_mode' => null]);

    expect($this->user->fresh()->transport_mode)->toBeNull();
});

test('rejects an unknown mode', function () {
    $this->postJson('/api/preferences/transport-mode', ['mode' => 'teleport'])
        ->assertUnprocessable();
});
