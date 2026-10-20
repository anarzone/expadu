<?php

use App\Http\Middleware\LocalMockClock;
use App\Models\User;
use App\Support\MockClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** Runs a request through the middleware and returns the clock it saw. */
function clockSeenWith(?string $cookie): CarbonImmutable
{
    $request = Request::create('/dashboard', 'GET', cookies: $cookie === null ? [] : [MockClock::COOKIE => $cookie]);
    $seen = null;

    (new LocalMockClock)->handle($request, function () use (&$seen) {
        $seen = CarbonImmutable::now();

        return response('ok');
    });

    return $seen;
}

test('the mock clock cookie does nothing outside the local environment', function () {
    expect(app()->environment('local'))->toBeFalse()
        ->and(clockSeenWith('2026-09-30T13:10:00+02:00')->format('Y-m-d H:i'))->not->toBe('2026-09-30 13:10')
        ->and(MockClock::active(Request::create('/', cookies: [MockClock::COOKIE => '2026-09-30T13:10:00+02:00'])))->toBeFalse();
});

test('on this machine the cookie sets the clock for that request only', function () {
    app()->detectEnvironment(fn () => 'local');

    $seen = clockSeenWith('2026-09-30T13:10:00+02:00');

    expect($seen->toIso8601String())->toBe('2026-09-30T13:10:00+02:00')
        ->and(CarbonImmutable::now()->format('Y-m-d'))->not->toBe('2026-09-30')
        ->and(now()->format('Y-m-d'))->not->toBe('2026-09-30');
});

test('a malformed or missing cookie leaves the real clock', function (?string $cookie) {
    app()->detectEnvironment(fn () => 'local');

    expect(clockSeenWith($cookie)->format('Y-m-d'))->toBe(CarbonImmutable::now()->format('Y-m-d'));
})->with([
    'missing' => [null],
    'words' => ['next tuesday'],
    'relative' => ['+3 days'],
    'array-like' => ['2026-09-30T13:10:00+02:00; drop'],
]);

test('the sample day refuses to write plans outside the local environment', function () {
    User::factory()->onboarded()->create(['email' => 'e2e@expadu.test']);

    $this->artisan('mock:sample-day', ['view' => 'busy'])->assertFailed();

    $this->assertDatabaseCount('composer_workspaces', 0);
});
