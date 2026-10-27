<?php

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('today', absolute: false));
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyFeature(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->create();

    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $response = $this->post(route('login'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
    // The failure must surface a visible error, not fail silently.
    $response->assertSessionHasErrors('email');
});

test('an authenticated user is bounced off the login screen with a flash', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect(route('today', absolute: false))
        ->assertSessionHas('status');
});

test('an authenticated, new user is returned from register to Today with a flash', function () {
    $user = User::factory()->notOnboarded()->create();

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect(route('today', absolute: false))
        ->assertSessionHas('status');
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $this->assertGuest();
    $response->assertRedirect('/login');
    $response->assertSessionHas('inertia.clear_history', true);
});

test('users are rate limited', function () {
    $user = User::factory()->create();

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

test('a wrong password and an unknown email get the same answer', function () {
    $user = User::factory()->create();

    $wrongPassword = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);
    $unknownEmail = $this->from(route('login'))->post(route('login.store'), [
        'email' => 'nobody-here@example.com',
        'password' => 'wrong-password',
    ]);

    $wrongPassword->assertSessionHasErrors(['email' => __('auth.failed')]);
    $unknownEmail->assertSessionHasErrors(['email' => __('auth.failed')]);
    $this->assertGuest();
});

test('a throttled sign-in says so on the poster, the same for any email', function (bool $hasAccount) {
    $email = $hasAccount ? User::factory()->create()->email : 'nobody-here@example.com';
    RateLimiter::increment(md5('login'.implode('|', [$email, '127.0.0.1'])), amount: 5);

    $response = $this->from(route('login'))
        ->withHeader('X-Inertia', 'true')
        ->post(route('login.store'), ['email' => $email, 'password' => 'wrong-password']);

    $response->assertRedirect(route('login'));
    expect(session('error'))->toStartWith('Too many requests');
    $this->assertGuest();
})->with(['an account' => true, 'no account' => false]);

test('signing in again comes back to the page the session ended on', function () {
    $user = User::factory()->create();

    $this->get(route('login', ['return' => '/explore?near=50.9,6.9&name=Neumarkt']))->assertOk();
    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticated();
    $response->assertRedirect('/explore?near=50.9,6.9&name=Neumarkt');
});

test('a return to another site is ignored', function (string $return) {
    $user = User::factory()->create();

    $this->get(route('login', ['return' => $return]))->assertOk();
    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $response->assertRedirect(route('today', absolute: false));
})->with([
    'another host' => ['https://evil.example/explore'],
    'protocol-relative' => ['//evil.example/explore'],
    'backslash' => ['/\\evil.example'],
    'not a path' => ['explore'],
]);

test('signing in again while still signed in goes straight back to the page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login', ['return' => '/explore?name=Neumarkt']))
        ->assertRedirect('/explore?name=Neumarkt');
    $this->actingAs($user)
        ->get(route('login', ['return' => '//evil.example']))
        ->assertRedirect(route('today'));
});
