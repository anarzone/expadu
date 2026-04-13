<?php

use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated onboarded users can visit the dashboard', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated non-onboarded users are redirected to onboarding', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('onboarding'));
});

test('dashboard returns unified feed', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->has('feed')
        ->has('feed.recommendations')
        ->has('weather')
    );
});
