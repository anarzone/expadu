<?php

use App\Models\User;

/*
 * The step-free need (owner, 21 Oct 2026): set by the person alone, through its one writer,
 * and never in what their account serialises.
 */

test('🔒 the step-free need is set and cleared by the signed-in person only', function () {
    $user = User::factory()->onboarded()->create(['needs_step_free' => false]);

    $this->put('/settings/needs/step-free', ['step_free' => true])->assertRedirect('/login');
    expect($user->fresh()->needs_step_free)->toBeFalse();

    $this->actingAs($user)->putJson('/settings/needs/step-free', ['step_free' => true])->assertOk()->assertExactJson(['step_free' => true]);
    expect($user->fresh()->needs_step_free)->toBeTrue()
        ->and($user->fresh()->toArray())->not->toHaveKey('needs_step_free');

    $this->actingAs($user)->putJson('/settings/needs/step-free', ['step_free' => 'maybe'])->assertUnprocessable();
    $this->actingAs($user)->putJson('/settings/needs/step-free', ['step_free' => false])->assertOk()->assertExactJson(['step_free' => false]);
    expect($user->fresh()->needs_step_free)->toBeFalse();
});
