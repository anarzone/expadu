<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

test('bot-paced auth posts hit a per-IP wall while pages stay open', function () {
    // fortify-forms: 30 unsafe requests per minute per IP shared across ALL
    // Fortify endpoints — bot signups, reset-mail bombing and cross-account
    // credential spraying drain the same budget.
    for ($i = 0; $i < 30; $i++) {
        $this->post('/register', []);
    }

    $this->postJson('/register', [])
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    // A throttled Inertia form post degrades to a flash toast (the exception
    // renderer), never the "something blocked the response" catch-all.
    $this->from('/register')
        ->post('/register', [], ['X-Inertia' => 'true'])
        ->assertRedirect('/register')
        ->assertSessionHas('error');

    // Safe methods are exempt — the form itself never locks out.
    $this->get('/register')->assertOk();
});

test('sensitive fortify endpoints all carry the shared bucket', function () {
    foreach (['register.store', 'login.store', 'password.email', 'password.update'] as $name) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())
            ->toContain('throttle:fortify-forms');
    }
});

test('expensive and abuse-prone routes carry their buckets', function () {
    $expected = [
        'api.geocode' => 'throttle:search',
        'api.reverse-geocode' => 'throttle:search',
        'api.stops' => 'throttle:search',
        'api.spots' => 'throttle:search',
        'api.journey.suggest' => 'throttle:search',
        'api.journey' => 'throttle:journey',
        'api.track' => 'throttle:app-writes',
        'composer.parse' => 'throttle:composer-parse',
        'reviews.store' => 'throttle:ugc',
        'social.redirect' => 'throttle:social',
        'profile.update' => 'throttle:app-writes',
        'user-password.update' => 'throttle:6,1',
        'waitlist.store' => 'throttle:10,1',
    ];

    foreach ($expected as $name => $middleware) {
        expect(Route::getRoutes()->getByName($name)->gatherMiddleware())
            ->toContain($middleware);
    }
});

test('the web group carries the global ceiling and it arms outside dev', function () {
    expect(app('router')->getMiddlewareGroups()['web'])->toContain('throttle:global');

    // Exempt under testing/local so browser suites and dev bursts never trip…
    expect(RateLimiter::limiter('global')(Request::create('/'))->maxAttempts)
        ->toBe(PHP_INT_MAX);

    // …but armed at 300/min per user-or-IP everywhere else.
    $this->app['env'] = 'production';

    try {
        $limit = RateLimiter::limiter('global')(Request::create('/'));
    } finally {
        $this->app['env'] = 'testing';
    }

    expect($limit->maxAttempts)->toBe(300);
});

test('write buckets exempt safe methods and cap mutations', function () {
    $writes = RateLimiter::limiter('app-writes');

    expect($writes(Request::create('/x', 'GET'))->maxAttempts)->toBe(PHP_INT_MAX)
        ->and($writes(Request::create('/x', 'POST'))->maxAttempts)->toBe(60);

    $fortify = RateLimiter::limiter('fortify-forms');

    expect($fortify(Request::create('/login', 'GET'))->maxAttempts)->toBe(PHP_INT_MAX)
        ->and($fortify(Request::create('/login', 'POST'))->maxAttempts)->toBe(30);
});
