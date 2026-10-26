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

test('Today sends everything in its first response', function () {
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    // Pages load whole (owner, 26 Oct 2026): nothing deferred, nothing in steps.
    $page = $this->get(route('dashboard'))->assertOk()->viewData('page');

    expect($page['props'])->toHaveKeys(['day', 'weather', 'forecast', 'station', 'paperwork', 'needsYou', 'discovery', 'comingUp', 'saved', 'continuation'])
        ->and($page['props']['day'])->toHaveKey('items')
        ->and($page['deferredProps'] ?? [])->toBe([]);
});

test('pages are history-encrypted unless a local preview turns it off', function () {
    $user = User::factory()->onboarded()->create();

    // Private props stay unreadable after logout on a shared device.
    expect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['encryptHistory'])->toBeTrue();

    config(['inertia.history.encrypt' => false]);
    expect($this->actingAs($user)->get(route('dashboard'))->viewData('page')['encryptHistory'] ?? false)->toBeFalse();
});
