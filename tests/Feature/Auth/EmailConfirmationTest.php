<?php

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Mail\ConfirmationCode;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

/** A Google-only account: signed up with Google, so no password. */
function googleOnly(array $attributes = []): User
{
    return User::factory()->onboarded()->create([
        'password' => null, 'social_provider' => 'google', 'social_id' => 'google-sub-1', ...$attributes,
    ]);
}

/** Asks for a code as the signed-in account and returns the one that was mailed. */
function mailedCode($test): string
{
    $test->postJson(route('email-confirmation.send'))->assertStatus(202);
    $code = null;
    Mail::assertSent(ConfirmationCode::class, function (ConfirmationCode $mail) use (&$code) {
        $code = $mail->code;

        return true;
    });

    return $code;
}

function isConfirmed($test): bool
{
    return $test->getJson(route('password.confirmation'))->assertOk()->json('confirmed');
}

test('🔒 an account without a password confirms with the emailed code, and then passes password.confirm', function () {
    $user = googleOnly(['email' => 'mover@example.com']);
    $person = app(EnsureAccountHolder::class)->person($user);
    app(EnsureAccountHolder::class)->dossier($user);
    $this->actingAs($user)->postJson('/bureaucracy/v2/people/'.$person->id.'/export')->assertStatus(423);

    $code = mailedCode($this);

    Mail::assertSent(ConfirmationCode::class, fn (ConfirmationCode $mail) => $mail->hasTo('mover@example.com'));
    Mail::assertSent(ConfirmationCode::class, 1);
    expect($code)->toMatch('/^\d{6}$/')
        ->and(isConfirmed($this))->toBeFalse();

    $this->postJson(route('email-confirmation.confirm'), ['code' => substr($code, 0, 3).' '.substr($code, 3)])->assertStatus(201);

    expect(isConfirmed($this))->toBeTrue();
    $this->postJson('/bureaucracy/v2/people/'.$person->id.'/export')->assertSuccessful()->assertJsonPath('person.id', $person->id);
});

test('🔒 the code is kept only as a hash, never in the session or the cache in clear', function () {
    $user = googleOnly();
    $this->actingAs($user);

    $code = mailedCode($this);

    $entry = Cache::get('email-confirmation:'.$user->id);
    expect($entry['code'])->toMatch('/^[0-9a-f]{64}$/')
        ->and(array_values(session()->all()))->not->toContain($code);
});

test('🔒 a code works once', function () {
    $this->actingAs(googleOnly());
    $code = mailedCode($this);

    $this->postJson(route('email-confirmation.confirm'), ['code' => $code])->assertStatus(201);
    session()->forget('auth.password_confirmed_at');

    $this->postJson(route('email-confirmation.confirm'), ['code' => $code])
        ->assertUnprocessable()->assertJsonValidationErrors(['code' => 'That code didn’t work. Check it, or send a new one.']);
    expect(isConfirmed($this))->toBeFalse();
});

test('🔒 a code expires after ten minutes, with the same answer as a wrong one', function () {
    $this->actingAs(googleOnly());
    $code = mailedCode($this);
    $wrong = $this->postJson(route('email-confirmation.confirm'), ['code' => $code === '000000' ? '111111' : '000000'])
        ->assertUnprocessable()->json('errors.code');

    $this->travel(11)->minutes();

    $expired = $this->postJson(route('email-confirmation.confirm'), ['code' => $code])->assertUnprocessable()->json('errors.code');
    expect($expired)->toBe($wrong)
        ->and(isConfirmed($this))->toBeFalse();
});

test('🔒 five wrong tries spend the code: even the right one fails after them', function () {
    $this->actingAs(googleOnly());
    $code = mailedCode($this);
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $try) {
        $this->postJson(route('email-confirmation.confirm'), ['code' => $wrong])->assertUnprocessable();
    }
    $this->postJson(route('email-confirmation.confirm'), ['code' => $code])->assertUnprocessable();

    expect(isConfirmed($this))->toBeFalse();
});

test('🔒 a code is bound to the session that asked for it', function () {
    $this->actingAs(googleOnly());
    $code = mailedCode($this);

    // The same account in another browser: a session without this one's nonce.
    $this->flushSession();

    $this->postJson(route('email-confirmation.confirm'), ['code' => $code])->assertUnprocessable();
    expect(isConfirmed($this))->toBeFalse();
});

test('🔒 one account\'s code never confirms another account', function () {
    $owner = googleOnly(['email' => 'owner@example.com']);
    $other = googleOnly(['email' => 'other@example.com', 'social_id' => 'google-sub-2']);
    $this->actingAs($owner);
    $code = mailedCode($this);

    $this->actingAs($other)->postJson(route('email-confirmation.confirm'), ['code' => $code])->assertUnprocessable();

    expect(isConfirmed($this))->toBeFalse();
});

test('🔒 an account with a password keeps its password: no code is sent or accepted', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)->postJson(route('email-confirmation.send'))->assertForbidden();
    $this->postJson(route('email-confirmation.confirm'), ['code' => '123456'])->assertForbidden();

    Mail::assertNothingSent();
    expect(isConfirmed($this))->toBeFalse();
});

test('🔒 sending is throttled per account and per address', function () {
    $this->actingAs(googleOnly());
    foreach (range(1, 3) as $send) {
        $this->postJson(route('email-confirmation.send'))->assertStatus(202);
    }
    $this->postJson(route('email-confirmation.send'))->assertTooManyRequests();
    Mail::assertSent(ConfirmationCode::class, 3);

    // A refused send doesn't count against the address.
    foreach (range(1, 7) as $account) {
        $this->actingAs(googleOnly(['email' => "mover{$account}@example.com", 'social_id' => "sub-{$account}"]));
        foreach (range(1, 3) as $send) {
            $this->postJson(route('email-confirmation.send'));
        }
    }
    // 3 from the first account, then 20 an hour from one address.
    Mail::assertSent(ConfirmationCode::class, 20);
});

test('🔒 guests can\'t ask for or use a code', function () {
    $this->postJson(route('email-confirmation.send'))->assertUnauthorized();
    $this->postJson(route('email-confirmation.confirm'), ['code' => '123456'])->assertUnauthorized();

    Mail::assertNothingSent();
});

test('🔒 the confirm page goes back to where the person was going, on this site only', function () {
    $user = googleOnly();
    $this->actingAs($user);

    $code = mailedCode($this);
    $this->withSession(['url.intended' => url('/bureaucracy')])
        ->post(route('email-confirmation.confirm'), ['code' => $code])
        ->assertRedirect(url('/bureaucracy'));

    $code = mailedCode($this);
    $this->withSession(['url.intended' => 'https://evil.example/steal'])
        ->post(route('email-confirmation.confirm'), ['code' => $code])
        ->assertRedirect(config('fortify.home'));

    $code = mailedCode($this);
    $this->withSession(['url.intended' => url('/').'.evil.example/steal'])
        ->post(route('email-confirmation.confirm'), ['code' => $code])
        ->assertRedirect(config('fortify.home'));
});

test('🔒 the shared props say only whether the account has a password', function () {
    $prop = fn (User $user) => $this->actingAs($user)->get(route('password.confirm'))->assertOk()->inertiaProps('auth');

    expect($prop(googleOnly())['has_password'])->toBeFalse()
        ->and($prop(User::factory()->onboarded()->create())['has_password'])->toBeTrue()
        ->and($prop(User::factory()->onboarded()->create())['user'])->not->toHaveKey('password');
});
