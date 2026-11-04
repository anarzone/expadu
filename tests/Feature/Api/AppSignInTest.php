<?php

use App\Auth\AppSignIn;
use App\Mail\ConfirmationCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialUser;

/*
 * The iPhone app's way in. Sanctum's sample route checks only email and password; these
 * hold it to what the web asks: two-step sign-in, throttling, and tokens that end.
 */

/** An account with two-step sign-in turned on and confirmed. */
function twoStepUser(array $recovery = ['recovery-one', 'recovery-two']): User
{
    $user = User::factory()->create();
    $user->forceFill([
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode($recovery)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

function codeIs(bool $right): void
{
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturn($right);
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
}

function signInBody(User $user, array $more = []): array
{
    return ['email' => $user->email, 'password' => 'password', 'device_name' => 'iPhone', ...$more];
}

test('🔒 the right password gets a token that opens the API; no token gets 401', function () {
    $user = User::factory()->create();

    $token = $this->postJson('/api/v1/auth/token', signInBody($user))->assertOk()
        ->assertJsonPath('two_factor', false)
        ->assertJsonPath('user.email', $user->email)
        ->json('token');

    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])->assertOk()->assertJsonPath('email', $user->email);
    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('🔒 a wrong password or unknown address gets no token, and the same answer', function () {
    $user = User::factory()->create();

    $wrong = $this->postJson('/api/v1/auth/token', signInBody($user, ['password' => 'nope']))->assertUnprocessable();
    $unknown = $this->postJson('/api/v1/auth/token', signInBody($user, ['email' => 'nobody@example.com']))->assertUnprocessable();

    expect($wrong->json('errors.email'))->toBe($unknown->json('errors.email'))
        ->and(PersonalAccessToken::count())->toBe(0);
});

test('🔒 an account without a password can’t get a token with one', function () {
    $user = User::factory()->create(['password' => null]);

    $this->postJson('/api/v1/auth/token', signInBody($user))->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
});

test('🔒 a browser session never opens the API', function () {
    $this->actingAs(User::factory()->create(), 'web');

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

test('🔒 two-step accounts get a challenge, never a token, until the code is right', function () {
    $user = twoStepUser();

    $first = $this->postJson('/api/v1/auth/token', signInBody($user))->assertOk()
        ->assertJsonPath('two_factor', true)
        ->assertJsonPath('token', null)
        ->assertJsonPath('user', null);
    expect(PersonalAccessToken::count())->toBe(0);

    codeIs(false);
    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $first->json('challenge'), 'code' => '000000'])->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);

    codeIs(true);
    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $first->json('challenge'), 'code' => '123456'])->assertOk()
        ->assertJsonPath('user.email', $user->email);
    expect(PersonalAccessToken::count())->toBe(1);

    // A challenge works once.
    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $first->json('challenge'), 'code' => '123456'])->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(1);
});

test('🔒 no code at all, or a made-up challenge, gets nothing', function () {
    $challenge = $this->postJson('/api/v1/auth/token', signInBody(twoStepUser()))->json('challenge');

    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge])->assertUnprocessable();
    $this->postJson('/api/v1/auth/two-factor', ['challenge' => 'made-up', 'code' => '123456'])->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);
});

test('🔒 a recovery code works once, then it is spent', function () {
    $user = twoStepUser();
    $challenge = fn () => $this->postJson('/api/v1/auth/token', signInBody($user))->json('challenge');

    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => 'recovery-one'])->assertOk();
    expect($user->fresh()->recoveryCodes())->not->toContain('recovery-one');

    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge(), 'recovery_code' => 'recovery-one'])->assertUnprocessable();
});

test('🔒 passwords are throttled as on the web, by address and machine', function () {
    $user = User::factory()->create();
    foreach (range(1, 5) as $try) {
        $this->postJson('/api/v1/auth/token', signInBody($user, ['password' => 'nope']))->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/token', signInBody($user))->assertTooManyRequests();
    expect(PersonalAccessToken::count())->toBe(0);
});

test('🔒 codes are throttled by the person: a new challenge buys no new guesses', function () {
    $user = twoStepUser();
    codeIs(false);
    foreach (range(1, 5) as $try) {
        RateLimiter::clear(md5('login'.mb_strtolower($user->email).'|127.0.0.1'));
        $challenge = app(AppSignIn::class)->challenge($user, 'iPhone', true);
        $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '000000'])->assertUnprocessable();
    }

    $challenge = app(AppSignIn::class)->challenge($user, 'iPhone', true);
    $this->postJson('/api/v1/auth/two-factor', ['challenge' => $challenge, 'code' => '123456'])->assertTooManyRequests();
});

test('🔒 tokens end: 60 days, or a day without Keep me signed in; expired ones are refused', function () {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/token', signInBody($user))->assertOk();
    expect(PersonalAccessToken::latest('id')->first()->expires_at->isBetween(now()->addDays(59), now()->addDays(61)))->toBeTrue();

    $short = $this->postJson('/api/v1/auth/token', signInBody($user, ['remember' => false]))->assertOk()->json('token');
    expect(PersonalAccessToken::latest('id')->first()->expires_at->isBetween(now()->addHours(23), now()->addHours(25)))->toBeTrue();

    $this->travel(25)->hours();
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$short}"])->assertUnauthorized();
});

test('🔒 refreshing replaces the token, and signing out ends it', function () {
    $old = $this->postJson('/api/v1/auth/token', signInBody(User::factory()->create()))->json('token');

    $new = $this->postJson('/api/v1/auth/refresh', [], ['Authorization' => "Bearer {$old}"])->assertOk()->json('token');
    expect($new)->not->toBe($old)->and(PersonalAccessToken::count())->toBe(1);

    $this->app['auth']->forgetGuards();
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$old}"])->assertUnauthorized();

    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/auth/token', [], ['Authorization' => "Bearer {$new}"])->assertNoContent();
    expect(PersonalAccessToken::count())->toBe(0);
});

test('🔒 a new password signs the phones out', function () {
    $user = User::factory()->create();
    $this->postJson('/api/v1/auth/token', signInBody($user))->assertOk();
    expect($user->tokens()->count())->toBe(1);

    $user->update(['password' => 'a-new-long-password']);

    expect($user->tokens()->count())->toBe(0);
});

test('🔒 Google’s code works once, only with the verifier the app started with', function () {
    $user = User::factory()->create();
    $verifier = str_repeat('v', 43);
    $pkce = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $exchange = fn (string $code, string $proof) => $this->postJson('/api/v1/auth/exchange', ['code' => $code, 'verifier' => $proof, 'device_name' => 'iPhone']);

    $exchange(app(AppSignIn::class)->codeFor($user, $pkce), str_repeat('x', 43))->assertUnprocessable();
    expect(PersonalAccessToken::count())->toBe(0);

    $code = app(AppSignIn::class)->codeFor($user, $pkce);
    $exchange($code, $verifier)->assertOk()->assertJsonPath('user.email', $user->email);
    $exchange($code, $verifier)->assertUnprocessable();
});

test('🔒 Google’s code still asks a two-step account for its code', function () {
    $verifier = str_repeat('v', 43);
    $pkce = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $this->postJson('/api/v1/auth/exchange', ['code' => app(AppSignIn::class)->codeFor(twoStepUser(), $pkce), 'verifier' => $verifier, 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonPath('two_factor', true)->assertJsonPath('token', null);
    expect(PersonalAccessToken::count())->toBe(0);
});

test('🔒 the sign-in sheet returns a code to the app, not a session', function () {
    $user = User::factory()->create(['email' => 'g@example.com']);
    $google = (new SocialUser)->map(['id' => 'g-1', 'name' => 'G', 'email' => 'g@example.com']);
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.example/o'));
    $driver->shouldReceive('user')->andReturn($google);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get('/auth/google/native')->assertInvalid(['challenge']);
    $this->get('/auth/google/native?challenge='.str_repeat('c', 43))->assertRedirect('https://accounts.example/o');

    $back = $this->get('/auth/google/callback');

    expect($back->headers->get('Location'))->toStartWith('expadu://auth?code=');
    $this->assertGuest('web');
    expect($user->tokens()->count())->toBe(0);
});

test('🔒 registering makes an account and sends its link, as the web does', function () {
    Event::fake([Registered::class]);

    $this->postJson('/api/v1/auth/register', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', 'device_name' => 'iPhone'])
        ->assertOk()->assertJsonPath('user.email_verified', false);

    Event::assertDispatched(Registered::class);
    expect(User::where('email', 'ana@example.com')->exists())->toBeTrue();
});

test('🔒 forgot password answers the same for an address with no account', function () {
    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => User::factory()->create()->email])->assertOk();
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk();

    expect($known->json())->toBe($unknown->json());
});

test('🔒 deleting asks for the password, then the account and its tokens are gone', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $token = $this->postJson('/api/v1/auth/token', signInBody($user))->json('token');
    $auth = ['Authorization' => "Bearer {$token}"];

    $this->deleteJson('/api/v1/me', ['password' => 'nope'], $auth)->assertUnprocessable();
    expect($user->fresh())->not->toBeNull();

    $this->deleteJson('/api/v1/me', ['password' => 'password'], $auth)->assertNoContent();

    expect(User::find($user->id))->toBeNull()->and(PersonalAccessToken::count())->toBe(0);
});

test('🔒 an account with no password deletes with the emailed code, bound to its own token', function () {
    Mail::fake();
    $user = User::factory()->create(['password' => null]);
    $mine = $user->createToken('iPhone')->plainTextToken;
    $other = $user->createToken('Other')->plainTextToken;

    $this->postJson('/api/v1/me/deletion-code', [], ['Authorization' => "Bearer {$mine}"])->assertOk()->assertJsonPath('sent', true);
    $sent = null;
    Mail::assertSent(ConfirmationCode::class, function ($mail) use (&$sent) {
        $sent = $mail->code;

        return true;
    });

    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['code' => $sent], ['Authorization' => "Bearer {$other}"])->assertUnprocessable();
    expect(User::find($user->id))->not->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->deleteJson('/api/v1/me', ['code' => $sent], ['Authorization' => "Bearer {$mine}"])->assertNoContent();
    expect(User::find($user->id))->toBeNull();
});
