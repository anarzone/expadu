<?php

use App\Mail\SecurityAlert;
use App\Mail\SecuritySummary;
use App\Models\BureaucracyEscalation;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Security\MailBudget;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->admin = User::factory()->create(['is_admin' => true, 'email' => 'owner@example.com']);
    User::factory()->create(['is_admin' => false, 'email' => 'person@example.com']);
    User::factory()->unverified()->create(['is_admin' => true, 'email' => 'unverified-admin@example.com']);
    config(['security_watch.hourly.signups' => 20]);
});

/** $n security-log rows of a type, $minutesAgo. */
function logged(int $n, string $type, int $minutesAgo = 5, array $more = []): void
{
    SecurityEvent::query()->insert(array_fill(0, $n, ['type' => $type, 'channel' => 'web', 'created_at' => now()->subMinutes($minutesAgo), ...$more]));
}

test('🔒 a burst of sign-ups alerts the admins once a day, by email and to the error reporter', function () {
    logged(20, 'signup');

    $this->artisan('security:watch')->assertSuccessful();
    $this->artisan('security:watch')->assertSuccessful();

    Mail::assertSent(SecurityAlert::class, 1);
    Mail::assertSent(SecurityAlert::class, fn (SecurityAlert $mail) => $mail->hasTo('owner@example.com') && $mail->rule === 'signups'
        && str_contains($mail->summary, '20 sign-ups in the last hour'));
    expect(BureaucracyEscalation::query()->where('kind', 'security_signups')->value('severity'))->toBe('high');
});

test('🔒 a normal hour, or a flood that ended an hour ago, alerts no one', function () {
    logged(19, 'signup');
    logged(50, 'login_failed', minutesAgo: 70);

    $this->artisan('security:watch')->assertSuccessful();

    Mail::assertNothingSent();
});

test('🔒 failed sign-ins, blocked posts, refused tries and a mostly used email budget each alert', function () {
    config(['security_watch.hourly' => ['signups' => 20, 'blocked' => 30, 'failed_logins' => 30, 'throttled' => 20], 'mail_budget.daily' => 10]);
    logged(25, 'login_failed');
    logged(5, 'two_factor_failed');
    logged(30, 'blocked', more: ['reason' => 'trap']);
    logged(20, 'throttled');
    $budget = app(MailBudget::class);
    foreach (range(1, 8) as $i) {
        $budget->admit('account', ["p{$i}@example.com"]);
    }

    $this->artisan('security:watch')->assertSuccessful();

    expect(Mail::sent(SecurityAlert::class)->pluck('rule')->sort()->values()->all())
        ->toBe(['blocked', 'failed_logins', 'mail_used', 'throttled']);
});

test('🔒 the morning summary goes to verified admins only, with the day’s numbers and no addresses', function () {
    logged(3, 'signup');
    logged(2, 'signup', more: ['channel' => 'waitlist']);
    logged(4, 'blocked', more: ['reason' => 'disposable', 'email_domain' => 'mailinator.com']);
    logged(1, 'login');
    logged(9, 'login', minutesAgo: 60 * 30);

    $this->artisan('security:daily-summary')->assertSuccessful();

    Mail::assertSent(SecuritySummary::class, 1);
    $mail = Mail::sent(SecuritySummary::class)->first();
    expect($mail->hasTo('owner@example.com'))->toBeTrue()
        ->and($mail->day['signups'])->toBe(['web' => 3, 'waitlist' => 2])
        ->and($mail->day['logins'])->toBe(1);

    $html = $mail->render();
    expect($html)->toContain('throwaway inbox 4')->toContain('mailinator.com 4')
        ->not->toContain('owner@example.com')->not->toContain('person@example.com');
});
