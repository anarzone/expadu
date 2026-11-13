<?php

use App\Models\SecurityEvent;
use App\Models\User;
use App\Privacy\Export\AccountData;
use Illuminate\Support\Facades\Schema;

/** A visitor at 203.0.113.7 reaching us through Cloudflare, as in production. */
function viaCloudflare(): array
{
    return ['REMOTE_ADDR' => '172.17.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 172.68.10.20', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Safari'];
}

/** @return list<array<string, mixed>> */
function securityLog(): array
{
    return SecurityEvent::query()->orderBy('id')->get()
        ->map(fn (SecurityEvent $e) => array_filter($e->only(['type', 'channel', 'user_id', 'email_domain', 'reason', 'ip']), fn ($v) => $v !== null))
        ->all();
}

// Real notifications into the array mailer, so the bot traps' mail hold runs.
beforeEach(fn () => config(['mail.default' => 'array']));

test('🔒 a sign-up is logged with the visitor’s IP and browser, and only the email’s domain', function () {
    $this->withServerVariables(viaCloudflare())->post(route('register.store'), [
        'name' => 'Ada', 'email' => 'ada@example.org', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', ...formDrawn(),
    ]);

    $user = User::query()->where('email', 'ada@example.org')->firstOrFail();
    expect(securityLog())->toContain(['type' => 'signup', 'channel' => 'web', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '203.0.113.7'])
        ->and(SecurityEvent::query()->value('user_agent'))->toBe('Mozilla/5.0 (iPhone) Safari');

    // The full address is nowhere in the log.
    expect(json_encode(SecurityEvent::query()->get()))->not->toContain('ada@');
});

test('🔒 sign-ins and failed sign-ins are logged, never the password typed', function () {
    $user = User::factory()->create(['email' => 'grace@example.org']);

    $this->withServerVariables(viaCloudflare())->post(route('login.store'), ['email' => 'grace@example.org', 'password' => 'not-it']);
    $this->withServerVariables(viaCloudflare())->post(route('login.store'), ['email' => 'nobody@elsewhere.net', 'password' => 'not-it']);
    $this->withServerVariables(viaCloudflare())->post(route('login.store'), ['email' => 'grace@example.org', 'password' => 'password']);

    expect(securityLog())->toBe([
        ['type' => 'login_failed', 'channel' => 'web', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '203.0.113.7'],
        ['type' => 'login_failed', 'channel' => 'web', 'email_domain' => 'elsewhere.net', 'ip' => '203.0.113.7'],
        ['type' => 'login', 'channel' => 'web', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '203.0.113.7'],
    ])->and(json_encode(SecurityEvent::query()->get()))->not->toContain('not-it');
});

test('🔒 too many wrong passwords log the lockout, on the website and in the app', function () {
    User::factory()->create(['email' => 'grace@example.org']);

    foreach (range(1, 6) as $i) {
        $this->post(route('login.store'), ['email' => 'grace@example.org', 'password' => 'not-it']);
        $this->postJson('/api/v1/auth/token', ['email' => 'grace@example.org', 'password' => 'not-it', 'device_name' => 'iPhone']);
    }

    // Every refused try is one entry; both share the 5-a-minute login limit.
    $throttled = SecurityEvent::query()->where('type', 'throttled')->get(['channel', 'reason', 'email_domain']);
    expect($throttled)->toHaveCount(7)
        ->and($throttled->map->only(['channel', 'reason', 'email_domain'])->unique()->values()->all())->toEqualCanonicalizing([
            ['channel' => 'web', 'reason' => 'login.store', 'email_domain' => 'example.org'],
            ['channel' => 'app', 'reason' => 'api/v1/auth/token', 'email_domain' => 'example.org'],
        ]);
});

test('🔒 the iPhone app’s sign-ins are logged as the app’s', function () {
    $user = User::factory()->create(['email' => 'grace@example.org']);

    $this->withServerVariables(viaCloudflare())->postJson('/api/v1/auth/token', ['email' => 'grace@example.org', 'password' => 'nope', 'device_name' => 'iPhone'])->assertUnprocessable();
    $this->withServerVariables(viaCloudflare())->postJson('/api/v1/auth/token', ['email' => 'grace@example.org', 'password' => 'password', 'device_name' => 'iPhone'])->assertOk();

    expect(securityLog())->toBe([
        ['type' => 'login_failed', 'channel' => 'app', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '203.0.113.7'],
        ['type' => 'login', 'channel' => 'app', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '203.0.113.7'],
    ]);
});

test('🔒 what the traps turn away is logged as blocked, with why', function () {
    // A scripted registration.
    $this->post(route('register.store'), ['name' => 'Bot', 'email' => 'bot@example.org', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);

    // A throwaway inbox on the waitlist.
    $this->postJson('/waitlist', ['email' => 'x@mailinator.com', 'city' => 'Köln', ...formDrawn()]);

    expect(securityLog())->toBe([
        ['type' => 'blocked', 'channel' => 'web', 'email_domain' => 'example.org', 'reason' => 'no_stamp', 'ip' => '127.0.0.1'],
        ['type' => 'blocked', 'channel' => 'waitlist', 'email_domain' => 'mailinator.com', 'reason' => 'disposable', 'ip' => '127.0.0.1'],
    ]);
});

test('🔒 a scripted reset request is logged as blocked only; a person’s as a request', function () {
    $user = User::factory()->create(['email' => 'grace@example.org']);

    $this->post(route('password.email'), ['email' => 'grace@example.org', ...formDrawn(1)]);
    $this->travel(2)->minutes();
    $this->post(route('password.email'), ['email' => 'grace@example.org', ...formDrawn()]);

    expect(securityLog())->toBe([
        ['type' => 'blocked', 'channel' => 'web', 'user_id' => $user->id, 'email_domain' => 'example.org', 'reason' => 'too_fast', 'ip' => '127.0.0.1'],
        ['type' => 'reset_requested', 'channel' => 'web', 'user_id' => $user->id, 'email_domain' => 'example.org', 'ip' => '127.0.0.1'],
    ]);
});

test('🔒 entries older than 90 days are deleted', function () {
    SecurityEvent::query()->insert([
        ['type' => 'login', 'channel' => 'web', 'created_at' => now()->subDays(91)],
        ['type' => 'login', 'channel' => 'web', 'created_at' => now()->subDays(89)],
    ]);

    $this->artisan('model:prune', ['--model' => [SecurityEvent::class]])->assertSuccessful();

    expect(SecurityEvent::query()->count())->toBe(1);
});

test('🔒 a person’s data export includes their own log entries and no one else’s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    SecurityEvent::query()->insert([
        ['type' => 'login', 'channel' => 'app', 'user_id' => $user->id, 'ip' => '203.0.113.7', 'created_at' => now()],
        ['type' => 'login', 'channel' => 'web', 'user_id' => $other->id, 'ip' => '198.51.100.1', 'created_at' => now()],
    ]);

    $account = json_decode(app(AccountData::class)->files($user)['account.json'], true)['data'];

    expect($account['security_log'])->toHaveCount(1)
        ->and($account['security_log'][0])->toMatchArray(['type' => 'login', 'channel' => 'app', 'ip' => '203.0.113.7']);
});

test('🔒 a failing log never stops someone signing in', function () {
    $user = User::factory()->create(['email' => 'grace@example.org']);
    Schema::drop('security_events');

    $this->post(route('login.store'), ['email' => 'grace@example.org', 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});
