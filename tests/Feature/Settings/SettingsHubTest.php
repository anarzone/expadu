<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the transit settings page renders', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('transit.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('settings/transit'));
});

test('the old privacy path redirects to the transit page', function () {
    // Location sharing folded into Transit & tickets; keep bookmarks working.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/privacy')
        ->assertRedirect('/settings/transit');
});

test('a single settings toggle persists without name/email (partial profile PATCH)', function () {
    $user = User::factory()->create(['has_deutschlandticket' => false]);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['has_deutschlandticket' => true])
        ->assertRedirect();

    expect($user->fresh()->has_deutschlandticket)->toBeTrue();
});

test('a guest cannot open the settings pages', function () {
    $this->get(route('transit.edit'))->assertRedirect(route('login'));
    $this->get('/settings/privacy')->assertRedirect(route('login'));
});
