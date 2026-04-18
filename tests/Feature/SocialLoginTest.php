<?php

use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('redirect sends user to provider', function () {
    Socialite::fake('google');

    $response = $this->get('/auth/google/redirect');

    $response->assertRedirect();
});

test('callback creates new user from social login', function () {
    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-123',
        'name' => 'Max Mustermann',
        'email' => 'max@example.com',
    ]));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect('/dashboard');

    $this->assertDatabaseHas('users', [
        'name' => 'Max Mustermann',
        'email' => 'max@example.com',
        'social_provider' => 'google',
        'social_id' => 'google-123',
    ]);

    $this->assertAuthenticated();
});

test('callback links social to existing email user', function () {
    $user = User::factory()->create(['email' => 'existing@example.com']);

    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-456',
        'name' => 'Existing User',
        'email' => 'existing@example.com',
    ]));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect('/dashboard');

    expect($user->fresh())
        ->social_provider->toBe('google')
        ->social_id->toBe('google-456');

    $this->assertAuthenticatedAs($user);
});

test('callback logs in returning social user', function () {
    $user = User::factory()->create([
        'social_provider' => 'google',
        'social_id' => 'google-789',
    ]);

    Socialite::fake('google', (new SocialiteUser)->map([
        'id' => 'google-789',
        'name' => $user->name,
        'email' => $user->email,
    ]));

    $response = $this->get('/auth/google/callback');

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);

    // No duplicate user created
    expect(User::where('social_id', 'google-789')->count())->toBe(1);
});

test('callback handles denied authorization gracefully', function () {
    Socialite::fake('google');

    // Callback without proper OAuth state will throw — controller catches it
    $response = $this->get('/auth/google/callback');

    $response->assertRedirect(route('login'));
});

test('invalid provider returns 404', function () {
    $this->get('/auth/facebook/redirect')->assertNotFound();
    $this->get('/auth/facebook/callback')->assertNotFound();
});
