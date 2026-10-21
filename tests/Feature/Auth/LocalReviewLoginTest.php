<?php

use App\Models\User;

beforeEach(function () {
    app()->instance('env', 'local');
    config([
        'local_review.enabled' => true,
        'local_review.email' => 'friendly-review@expadu.test',
    ]);
});

test('local review opens the configured test account without credentials', function () {
    $reviewer = User::factory()->create(['email' => 'friendly-review@expadu.test', 'is_admin' => false]);
    $other = User::factory()->create();

    $this->get('/dev/review?user_id='.$other->id)->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($reviewer);
    $this->get('/composer/workspace')->assertOk();
});

test('local review does not replace an existing signed in user', function () {
    User::factory()->create(['email' => 'friendly-review@expadu.test']);
    $existing = User::factory()->create();

    $this->actingAs($existing)->get('/dev/review')->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($existing);
});

test('local review is unavailable outside the local environment even when enabled', function (string $environment) {
    app()->instance('env', $environment);
    User::factory()->create(['email' => 'friendly-review@expadu.test']);

    $this->get('/dev/review')->assertNotFound();
    $this->assertGuest();
})->with(['production', 'staging', 'testing']);

test('local review is unavailable unless explicitly enabled', function () {
    config(['local_review.enabled' => false]);
    User::factory()->create(['email' => 'friendly-review@expadu.test']);

    $this->get('/dev/review')->assertNotFound();
    $this->assertGuest();
});

test('local review rejects remote or proxied requests', function (array $server, string $url) {
    User::factory()->create(['email' => 'friendly-review@expadu.test']);

    $this->withServerVariables($server)->get($url)->assertNotFound();
    $this->assertGuest();
})->with([
    'remote peer' => [['REMOTE_ADDR' => '203.0.113.10'], '/dev/review'],
    'remote host' => [[], 'http://app.expadu.com/dev/review'],
    'forwarded peer' => [['HTTP_X_FORWARDED_FOR' => '203.0.113.10'], '/dev/review'],
]);

test('local review cannot access an admin unverified or non-test account', function (array $attributes) {
    $reviewer = User::factory()->create(array_merge([
        'email' => 'friendly-review@expadu.test',
        'is_admin' => false,
    ], $attributes));
    config(['local_review.email' => $reviewer->email]);

    $this->get('/dev/review')->assertNotFound();
    $this->assertGuest();
})->with([
    'admin' => [['is_admin' => true]],
    'unverified' => [['email_verified_at' => null]],
    'ordinary address' => [['email' => 'person@example.com']],
    'two factor protected' => [['two_factor_secret' => 'do-not-bypass']],
]);

test('local review without the configured account fails closed', function () {
    User::factory()->create();

    $this->get('/dev/review')->assertNotFound();
    $this->assertGuest();
});

test('enabling review entry does not make protected pages public', function () {
    $this->get('/dashboard')->assertRedirect(route('login'));
    $this->getJson('/composer/workspace')->assertUnauthorized();
    $this->assertGuest();
});
