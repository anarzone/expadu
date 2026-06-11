<?php

use App\Models\User;

test('places page renders the placeholder', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('explore'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('places'));
});

test('places page requires onboarding', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $this->get(route('explore'))->assertRedirect(route('onboarding'));
});
