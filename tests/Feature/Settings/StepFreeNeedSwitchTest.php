<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia;

// The account menu's step-free switch reads the person's own need from the
// shared `needs` prop; the column itself stays out of auth.user.

test('the account menu sees the signed-in person’s own step-free need, and auth.user doesn’t carry it', function () {
    $user = User::factory()->onboarded()->create(['needs_step_free' => true]);

    $this->actingAs($user)->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('needs.step_free', true)
            ->missing('auth.user.needs_step_free'));
});

test('turning the switch off is read back off', function () {
    $user = User::factory()->onboarded()->create(['needs_step_free' => true]);

    $this->actingAs($user)->putJson(route('needs.step-free.update'), ['step_free' => false])->assertOk();

    $this->actingAs($user)->get(route('settings'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('needs.step_free', false));
});

test('a guest has no needs', function () {
    $this->get(route('login'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('needs', null));
});
