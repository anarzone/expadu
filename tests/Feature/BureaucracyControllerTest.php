<?php

use App\Models\User;

test('bureaucracy page renders for an onboarded user', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $this->get(route('bureaucracy'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('bureaucracy')
            ->has('tasks')
        );
});

test('bureaucracy page requires authentication', function () {
    $this->get(route('bureaucracy'))
        ->assertRedirect(route('login'));
});
