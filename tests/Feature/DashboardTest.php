<?php

uses()->group('slow');
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

test('authenticated new users enter Today without a questionnaire', function () {
    $user = User::factory()->notOnboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    expect($user->fresh()->onboarded_at)->toBeNull();
});

test('dashboard defers the day and weather', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->missing('day')
        ->missing('weather')
        ->loadDeferredProps(fn ($reload) => $reload
            ->has('day.items')
            ->has('weather')
        )
    );
});

test('pages are history-encrypted unless a local preview turns it off', function () {
    $user = User::factory()->onboarded()->create();

    // Private props stay unreadable after logout on a shared device.
    expect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['encryptHistory'])->toBeTrue();

    config(['inertia.history.encrypt' => false]);
    expect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['encryptHistory'] ?? false)->toBeFalse();
});
