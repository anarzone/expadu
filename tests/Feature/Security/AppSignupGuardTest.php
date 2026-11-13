<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

function appSignup(array $more = [], string $ip = '203.0.113.7'): TestResponse
{
    static $n = 0;
    $n++;

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/register', [
        'name' => 'Ana', 'email' => "ana{$n}@example.com", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password',
        'device_name' => 'iPhone', ...$more,
    ]);
}

test('🔒 the app’s sign-up takes the stamp from GET /auth/form, once it’s a few seconds old', function () {
    $drawn = $this->getJson('/api/v1/auth/form')->assertOk()->json('drawn');

    appSignup(['drawn' => $drawn])->assertUnprocessable();
    $this->travel(5)->seconds();
    appSignup(['drawn' => $drawn])->assertOk();

    expect(User::query()->count())->toBe(1);
});

test('🔒 a sign-up with no stamp, a forged one, or one used before makes no account', function () {
    appSignup()->assertUnprocessable()->assertJsonValidationErrors('drawn');
    appSignup(['drawn' => 'not-a-real-stamp'])->assertUnprocessable();

    $stamp = formDrawn()['drawn'];
    appSignup(['drawn' => $stamp])->assertOk();
    appSignup(['drawn' => $stamp])->assertUnprocessable()->assertJsonFragment(['email' => ['We couldn’t create your account just now. Please try again.']]);

    expect(User::query()->count())->toBe(1)
        ->and(SecurityEvent::query()->where('type', 'blocked')->pluck('reason')->all())->toBe(['no_stamp', 'reused']);
});

test('🔒 one address makes at most five accounts a day, website and app together', function () {
    foreach (range(1, CreateNewUser::DailyPerIp) as $i) {
        appSignup(formDrawn())->assertOk();
    }

    appSignup(formDrawn())->assertUnprocessable()
        ->assertJsonFragment(['email' => ['Too many new accounts from this connection today. Please try again tomorrow.']]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post(route('register.store'), [
        'name' => 'Web', 'email' => 'web@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', ...formDrawn(),
    ])->assertSessionHasErrors('email');
    appSignup(formDrawn(), ip: '198.51.100.20')->assertOk();

    $this->travel(25)->hours();
    appSignup(formDrawn())->assertOk();
});

test('🔒 the app’s reset request without a fresh stamp mails no one, and says the same', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'grace@example.org']);

    $scripted = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'grace@example.org'])->assertOk();
    $tooFast = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'grace@example.org', ...formDrawn(1)])->assertOk();
    Notification::assertNothingSent();

    $person = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'grace@example.org', ...formDrawn()])->assertOk();
    Notification::assertSentTo($user, ResetPassword::class);

    expect($scripted->json())->toBe($person->json())->and($tooFast->json())->toBe($person->json());
});

test('🔒 on the website too, one stamp makes one account', function () {
    $stamp = formDrawn();
    $post = fn (string $email) => $this->post(route('register.store'), [
        'name' => 'Web', 'email' => $email, 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', ...$stamp,
    ]);

    $post('first@example.com');
    auth()->logout();
    $post('second@example.com')->assertSessionHasErrors('email');

    expect(User::query()->pluck('email')->all())->toBe(['first@example.com']);
});
