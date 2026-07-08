<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Support\Facades\Notification;

test('the User model enforces email verification', function () {
    expect(User::factory()->make())->toBeInstanceOf(MustVerifyEmail::class);
});

test('an unverified user is bounced from the app to the verify-email notice', function () {
    $user = User::factory()->onboarded()->unverified()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user reaches the app', function () {
    $user = User::factory()->onboarded()->create(); // verified by default

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('changing your email un-verifies it and sends a fresh verification link', function () {
    Notification::fake();

    $user = User::factory()->create(); // verified by default

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => 'changed-'.$user->email,
        ])
        ->assertRedirect();

    expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('the backfill grandfathers existing unverified accounts as verified', function () {
    // A legacy account that predates verification going live.
    $legacy = User::factory()->unverified()->create();
    expect($legacy->hasVerifiedEmail())->toBeFalse();

    // The migration is already applied in the test DB, so exercise its up()
    // logic directly (the file returns the migration instance).
    $migration = require database_path(
        'migrations/2026_07_08_162650_backfill_existing_users_as_verified.php'
    );
    $migration->up();

    expect($legacy->fresh()->hasVerifiedEmail())->toBeTrue();
});
