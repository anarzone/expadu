<?php

use App\Mail\WaitlistConfirmation;
use App\Marketing\WaitlistGuard;
use App\Models\WaitlistSignup;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
});

/** A form drawn $secondsAgo, as a person's browser posts it. */
function waitlistFormDrawn(int $secondsAgo = 30): array
{
    return [WaitlistGuard::Stamp => Crypt::encryptString((string) now()->subSeconds($secondsAgo)->getTimestamp())];
}

test('a signup is stored unconfirmed and receives exactly one confirmation mail', function () {
    $response = $this->postJson('/waitlist', [
        'email' => 'newcomer@example.com',
        'city' => 'Düsseldorf',
        'source' => 'landing-footer',
        ...waitlistFormDrawn(),
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
        ...waitlistFormDrawn(),
    ])->assertCreated();

    expect(WaitlistSignup::query()->where('email', 'mixed@example.com')->exists())->toBeTrue();
});

test('resubmitting while unconfirmed updates the city and re-sends, without duplicating the row', function () {
    $signup = WaitlistSignup::factory()->create(['email' => 'again@example.com', 'city' => 'Bonn']);

    $this->postJson('/waitlist', [
        'email' => 'again@example.com',
        'city' => 'München',
        ...waitlistFormDrawn(),
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
        ...waitlistFormDrawn(),
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

test('a second signup for the same address within a day sends no second mail', function () {
    $this->postJson('/waitlist', ['email' => 'twice@example.com', 'city' => 'Köln', ...waitlistFormDrawn()])->assertCreated();
    $this->postJson('/waitlist', ['email' => 'twice@example.com', 'city' => 'Bonn', ...waitlistFormDrawn()])->assertCreated();

    expect(WaitlistSignup::query()->where('email', 'twice@example.com')->value('city'))->toBe('Bonn');
    Mail::assertQueued(WaitlistConfirmation::class, 1);
});

test('scripted posts get the usual reply but are not saved and mail no one', function (array $post) {
    $this->postJson('/waitlist', ['email' => 'stranger@example.com', 'city' => 'Cologne', ...$post])
        ->assertCreated()
        ->assertJsonFragment(['message' => 'Check your inbox to confirm — then you’re on the list.']);

    expect(WaitlistSignup::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'the hidden field filled in' => [fn () => [WaitlistGuard::Trap => 'https://spam.example', ...waitlistFormDrawn()]],
    'no stamp of when the form was drawn' => [fn () => []],
    'a forged stamp' => [fn () => [WaitlistGuard::Stamp => 'not-a-real-stamp']],
    'posted a second after the form was drawn' => [fn () => waitlistFormDrawn(1)],
]);

test('a link or markup in the city field is rejected', function () {
    $this->postJson('/waitlist', [
        'email' => 'stranger@example.com',
        'city' => '* * * <a href="https://example.com">$3,222 credit available</a> * * *',
        ...waitlistFormDrawn(),
    ])->assertUnprocessable()->assertJsonValidationErrors('city');

    Mail::assertNothingQueued();
});

test('a form left open for days asks to be reloaded and mails no one', function () {
    $this->postJson('/waitlist', ['email' => 'late@example.com', 'city' => 'Köln', ...waitlistFormDrawn(2 * 86_400)])
        ->assertUnprocessable();

    expect(WaitlistSignup::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('both waitlist forms carry the traps', function () {
    foreach (['/', '/blog'] as $page) {
        $this->get($page)->assertOk()
            ->assertSee('name="'.WaitlistGuard::Trap.'"', escape: false)
            ->assertSee('name="'.WaitlistGuard::Stamp.'"', escape: false);
    }
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
