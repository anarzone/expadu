<?php

use App\Models\User;

test('the landing page offers login CTAs to guests', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('welcome')
        ->where('auth.user', null)
        ->has('appUrl')
    );
});

test('a signed-in visitor gets the landing page with their identity and an app link, not a login bounce', function () {
    // Onboarded: a non-onboarded user is bounced to onboarding by the global
    // middleware and never reaches the landing page. The visitor who actually
    // lands here (and used to see a Log in button) is a fully signed-in user.
    $user = User::factory()->onboarded()->create();
    $this->actingAs($user);

    // The landing page is NOT guest-only: an authenticated visitor reaches it
    // without the "you're already signed in" redirect, and receives what the
    // page needs to swap Log in / Get started for "Open the app" — the shared
    // auth.user plus the absolute appUrl to the app.
    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('welcome')
        ->where('auth.user.id', $user->id)
        ->has('appUrl')
    );
});
