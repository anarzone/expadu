<?php

use App\Mail\WaitlistConfirmation;
use App\Models\User;
use App\Models\WaitlistSignup;
use App\Waitlist\CoverageWaitlist;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

function outsideCologne(array $attributes = []): User
{
    return User::factory()->onboarded()->create(['email' => 'Mover@Example.com', 'city' => 'Hamburg', ...$attributes]);
}

test('🔒 joining asks for consent by mail: one unconfirmed row with the account\'s own email, city and source', function () {
    $user = outsideCologne();

    $this->actingAs($user)->post(route('paperwork.waitlist'))->assertRedirect();

    $signup = WaitlistSignup::query()->sole();
    expect($signup->email)->toBe('mover@example.com')
        ->and($signup->city)->toBe('Hamburg')
        ->and($signup->source)->toBe('paperwork')
        ->and($signup->isConfirmed())->toBeFalse()
        ->and(app(CoverageWaitlist::class)->state($user))->toBe('unconfirmed');
    Mail::assertQueued(WaitlistConfirmation::class, fn (WaitlistConfirmation $mail) => $mail->hasTo('mover@example.com'));
    Mail::assertQueued(WaitlistConfirmation::class, 1);
});

test('🔒 it signs up only the account itself: a submitted address is ignored', function () {
    $this->actingAs(outsideCologne())->post(route('paperwork.waitlist'), ['email' => 'someone-else@example.com', 'city' => 'Köln'])->assertRedirect();

    expect(WaitlistSignup::query()->pluck('email')->all())->toBe(['mover@example.com'])
        ->and(WaitlistSignup::query()->sole()->city)->toBe('Hamburg');
    Mail::assertNotQueued(WaitlistConfirmation::class, fn (WaitlistConfirmation $mail) => $mail->hasTo('someone-else@example.com'));
});

test('🔒 a second tap keeps one row and re-sends only while unconfirmed; a confirmed address gets nothing more', function () {
    $user = outsideCologne();

    $this->actingAs($user)->post(route('paperwork.waitlist'));
    $this->actingAs($user)->post(route('paperwork.waitlist'));
    expect(WaitlistSignup::query()->count())->toBe(1);
    Mail::assertQueued(WaitlistConfirmation::class, 2);

    WaitlistSignup::query()->sole()->forceFill(['confirmed_at' => now()])->save();
    $this->actingAs($user)->post(route('paperwork.waitlist'));

    Mail::assertQueued(WaitlistConfirmation::class, 2);
    expect(app(CoverageWaitlist::class)->state($user))->toBe('confirmed');
});

test('🔒 someone who confirmed on the website is already on the list: no mail, and the page says so', function () {
    $user = outsideCologne();
    WaitlistSignup::factory()->confirmed()->create(['email' => 'mover@example.com', 'city' => 'Hamburg', 'source' => 'landing-footer']);

    $this->actingAs($user)->post(route('paperwork.waitlist'));

    Mail::assertNothingQueued();
    expect(WaitlistSignup::query()->sole()->source)->toBe('landing-footer')
        ->and(app(CoverageWaitlist::class)->state($user))->toBe('confirmed');
});

test('🔒 guests and unverified accounts can\'t join from Paperwork', function () {
    $this->post(route('paperwork.waitlist'))->assertRedirect(route('login'));
    $this->actingAs(outsideCologne(['email_verified_at' => null]))->post(route('paperwork.waitlist'))->assertRedirect();

    expect(WaitlistSignup::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

test('🔒 the page reads only the signed-in account\'s own place on the list, and only outside coverage', function () {
    $mover = outsideCologne();
    $other = User::factory()->onboarded()->create(['email' => 'other@example.com', 'city' => 'Hamburg']);
    WaitlistSignup::factory()->confirmed()->create(['email' => 'other@example.com', 'city' => 'Hamburg']);
    $prop = fn (User $user) => $this->actingAs($user)->get(route('bureaucracy'))->assertOk()->inertiaProps('coverageWaitlist');

    expect($prop($mover))->toBeNull()
        ->and($prop($other))->toBe('confirmed');

    $this->actingAs($mover)->post(route('paperwork.waitlist'));
    expect($prop($mover))->toBe('unconfirmed');

    $cologne = User::factory()->onboarded()->create(['email' => 'local@example.com', 'city' => 'Köln']);
    WaitlistSignup::factory()->create(['email' => 'local@example.com', 'city' => 'Köln']);
    expect($prop($cologne))->toBeNull();
});
