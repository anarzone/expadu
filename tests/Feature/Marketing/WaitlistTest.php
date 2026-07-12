<?php

use App\Mail\WaitlistConfirmation;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
});

test('a signup is stored unconfirmed and receives exactly one confirmation mail', function () {
    $response = $this->postJson('/waitlist', [
        'email' => 'newcomer@example.com',
        'city' => 'Düsseldorf',
        'source' => 'landing-footer',
    ]);

    $response->assertCreated();

    $signup = WaitlistSignup::query()->where('email', 'newcomer@example.com')->firstOrFail();
    expect($signup->isConfirmed())->toBeFalse()
        ->and($signup->city)->toBe('Düsseldorf');

    Mail::assertQueued(WaitlistConfirmation::class, 1);
});

test('the email address is normalised to lowercase', function () {
    $this->postJson('/waitlist', [
        'email' => 'MiXeD@Example.COM',
        'city' => 'Bonn',
    ])->assertCreated();

    expect(WaitlistSignup::query()->where('email', 'mixed@example.com')->exists())->toBeTrue();
});

test('resubmitting while unconfirmed updates the city and re-sends, without duplicating the row', function () {
    $signup = WaitlistSignup::factory()->create(['email' => 'again@example.com', 'city' => 'Bonn']);

    $this->postJson('/waitlist', [
        'email' => 'again@example.com',
        'city' => 'München',
    ])->assertCreated();

    expect(WaitlistSignup::query()->where('email', 'again@example.com')->count())->toBe(1)
        ->and($signup->fresh()->city)->toBe('München');

    Mail::assertQueued(WaitlistConfirmation::class, 1);
});

test('a confirmed address gets a friendly response and no further mail', function () {
    WaitlistSignup::factory()->confirmed()->create(['email' => 'done@example.com']);

    $response = $this->postJson('/waitlist', [
        'email' => 'done@example.com',
        'city' => 'Hamburg',
    ]);

    $response->assertOk();
    $response->assertJsonFragment(['message' => 'You’re already on the list — we’ll be in touch.']);

    Mail::assertNothingQueued();
});

test('an invalid email is rejected', function () {
    $this->postJson('/waitlist', [
        'email' => 'not-an-email',
        'city' => 'Köln',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(WaitlistSignup::query()->count())->toBe(0);
});

test('the signed confirmation link confirms the signup once', function () {
    $signup = WaitlistSignup::factory()->create();

    $url = URL::temporarySignedRoute('waitlist.confirm', now()->addDays(7), ['signup' => $signup->id]);

    $response = $this->get($url);

    $response->assertOk();
    $response->assertSee('You’re on the list.');
    expect($signup->fresh()->isConfirmed())->toBeTrue();

    // Idempotent: the timestamp does not move on a second visit.
    $first = $signup->fresh()->confirmed_at;
    $this->travel(1)->hours();
    $this->get($url)->assertOk();
    expect($signup->fresh()->confirmed_at->equalTo($first))->toBeTrue();
});

test('an unsigned or tampered confirmation link is rejected', function () {
    $signup = WaitlistSignup::factory()->create();

    $this->get("/waitlist/confirm/{$signup->id}")->assertForbidden();

    expect($signup->fresh()->isConfirmed())->toBeFalse();
});
