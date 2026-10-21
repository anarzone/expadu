<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyFeature(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));

        return true;
    });
});

test('password cannot be reset with invalid token', function () {
    $user = User::factory()->create();

    $response = $this->post(route('password.update'), [
        'token' => 'invalid-token',
        'email' => $user->email,
        'password' => 'newpassword123',
        'password_confirmation' => 'newpassword123',
    ]);

    $response->assertSessionHasErrors('email');
});

test('a reset link request answers the same whether or not the email has an account', function () {
    Notification::fake();
    $user = User::factory()->create();

    $known = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->from(route('password.request'))->post(route('password.email'), ['email' => 'nobody-here@example.com']);
    // Only an account's link can be throttled: asking again within the
    // minute must not answer differently.
    $again = $this->from(route('password.request'))->post(route('password.email'), ['email' => $user->email]);

    foreach ([$known, $unknown, $again] as $response) {
        $response->assertSessionHasNoErrors()->assertSessionHas('status', __('passwords.sent'))->assertRedirect(route('password.request'));
    }
    Notification::assertSentTimes(ResetPassword::class, 1);
});

test('the reset page asks for no referrer, so its token stays out of other requests', function () {
    $this->get(route('password.reset', ['token' => 'a-reset-token', 'email' => 'someone@example.com']))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->get(route('password.request'))->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
