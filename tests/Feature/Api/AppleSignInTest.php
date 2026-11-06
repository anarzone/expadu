<?php

use App\Auth\AppleIdentity;
use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/*
 * Sign in with Apple on the iPhone. The identity token is the only proof of who someone is,
 * so each way a token can be wrong has to end without an account or a token.
 */

/** A key pair standing in for one of Apple's, and its public half as Apple publishes it. */
function appleKey(string $kid = 'test-key'): array
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $private);
    $details = openssl_pkey_get_details($key)['rsa'];
    $url = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    return ['private' => $private, 'jwk' => ['kty' => 'RSA', 'kid' => $kid, 'use' => 'sig', 'alg' => 'RS256', 'n' => $url($details['n']), 'e' => $url($details['e'])]];
}

function publishAppleKeys(array ...$keys): void
{
    Http::fake([AppleIdentity::KEYS_URL => Http::response(['keys' => array_map(fn ($k) => $k['jwk'], $keys)])]);
}

function appleToken(array $key, array $claims = [], string $nonce = 'a-nonce-the-app-made-for-this-sheet-only'): string
{
    return JWT::encode([
        'iss' => AppleIdentity::ISSUER,
        'aud' => 'com.expadu.app',
        'sub' => '001234.apple-user',
        'iat' => time(),
        'exp' => time() + 600,
        'nonce' => hash('sha256', $nonce),
        'email' => 'ada@privaterelay.appleid.com',
        'email_verified' => 'true',
        ...$claims,
    ], $key['private'], 'RS256', $key['jwk']['kid']);
}

function appleBody(string $token, array $more = []): array
{
    return ['identity_token' => $token, 'nonce' => 'a-nonce-the-app-made-for-this-sheet-only', 'device_name' => 'iPhone', ...$more];
}

beforeEach(fn () => config(['services.apple.bundle_id' => 'com.expadu.app']));

test('🔒 a good token makes the account, its email verified, and a token that opens the API', function () {
    $key = appleKey();
    publishAppleKeys($key);

    $token = $this->postJson('/api/v1/auth/apple', appleBody(appleToken($key), ['name' => 'Ada']))->assertOk()
        ->assertJsonPath('user.email', 'ada@privaterelay.appleid.com')
        ->json('token');

    $user = User::where('apple_id', '001234.apple-user')->sole();
    expect($user->name)->toBe('Ada')->and($user->hasVerifiedEmail())->toBeTrue();
    $this->getJson('/api/v1/me', ['Authorization' => "Bearer {$token}"])->assertOk();
});

test('🔒 the same Apple ID opens the same account later, when Apple no longer sends the email', function () {
    $key = appleKey();
    publishAppleKeys($key);
    $user = User::factory()->create();
    $user->forceFill(['apple_id' => '001234.apple-user'])->save();

    $this->postJson('/api/v1/auth/apple', appleBody(appleToken($key, ['email' => null, 'email_verified' => null])))
        ->assertOk()->assertJsonPath('user.email', $user->email);

    expect(User::count())->toBe(1);
});

test('🔒 a verified address joins the account that has it', function () {
    $key = appleKey();
    publishAppleKeys($key);
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/auth/apple', appleBody(appleToken($key, ['email' => 'ada@example.com'])))->assertOk();

    expect($user->fresh()->apple_id)->toBe('001234.apple-user')->and(User::count())->toBe(1);
});

test('🔒 an unverified address never takes over the account that has it', function () {
    $key = appleKey();
    publishAppleKeys($key);
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->postJson('/api/v1/auth/apple', appleBody(appleToken($key, ['email' => 'ada@example.com', 'email_verified' => 'false'])))
        ->assertUnprocessable()->assertJsonMissingPath('token');

    expect($user->fresh()->apple_id)->toBeNull()->and(User::count())->toBe(1);
});

test('🔒 a token that is wrong in any way gets no account and no token', function (Closure $make) {
    $key = appleKey();
    publishAppleKeys($key);

    [$token, $nonce] = $make($key);
    $this->postJson('/api/v1/auth/apple', appleBody($token, ['nonce' => $nonce]))
        ->assertUnprocessable()->assertJsonValidationErrors('identity_token')->assertJsonMissingPath('token');

    expect(User::count())->toBe(0);
})->with([
    'for another app' => [fn ($key) => [appleToken($key, ['aud' => 'com.someone.else']), 'a-nonce-the-app-made-for-this-sheet-only']],
    'not from Apple' => [fn ($key) => [appleToken($key, ['iss' => 'https://example.com']), 'a-nonce-the-app-made-for-this-sheet-only']],
    'expired' => [fn ($key) => [appleToken($key, ['exp' => time() - 60]), 'a-nonce-the-app-made-for-this-sheet-only']],
    'a different nonce' => [fn ($key) => [appleToken($key), 'some-other-nonce-that-the-app-never-sent']],
    'signed by a key Apple never published' => [fn ($key) => [appleToken(appleKey()), 'a-nonce-the-app-made-for-this-sheet-only']],
    'not a token at all' => [fn ($key) => ['not-a-token', 'a-nonce-the-app-made-for-this-sheet-only']],
]);

test('🔒 when Apple rotates its keys, an unknown key ID fetches them again', function () {
    $old = appleKey('old');
    $new = appleKey('new');
    Http::fakeSequence(AppleIdentity::KEYS_URL)
        ->push(['keys' => [$old['jwk']]])
        ->push(['keys' => [$old['jwk'], $new['jwk']]]);

    $this->postJson('/api/v1/auth/apple', appleBody(appleToken($new)))->assertOk();
});

test('🔒 an account with two-step sign-in gets the challenge, not a token', function () {
    $key = appleKey();
    publishAppleKeys($key);
    $user = User::factory()->create();
    $user->forceFill([
        'apple_id' => '001234.apple-user',
        'two_factor_secret' => encrypt('test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['one'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->postJson('/api/v1/auth/apple', appleBody(appleToken($key)))->assertOk()
        ->assertJsonPath('two_factor', true)->assertJsonPath('token', null);
});
