<?php

use App\Mail\WaitlistConfirmation;
use App\Models\BureaucracyEscalation;
use App\Models\User;
use App\Models\WaitlistSignup;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config(['mail.default' => 'array', 'mail_budget.daily' => 10, 'mail_budget.kinds.waitlist.daily' => 3, 'mail_budget.kinds.other.daily' => 2]);
});

/** Mail actually handed to the transport. */
function mailsDelivered(): int
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
}

function sendWaitlistMail(string $email): void
{
    Mail::to($email)->send(new WaitlistConfirmation(WaitlistSignup::factory()->create(['email' => $email])));
}

test('the waitlist stops at its share of the day, and account mail still goes', function () {
    foreach (range(1, 5) as $i) {
        sendWaitlistMail("w{$i}@example.com");
    }
    expect(mailsDelivered())->toBe(3);

    User::factory()->create()->notify(new ResetPassword('token'));
    expect(mailsDelivered())->toBe(4);
});

test('one address gets its kind\'s share a day, however often it is asked for', function () {
    $user = User::factory()->create();
    foreach (range(1, 8) as $i) {
        $user->notify(new ResetPassword("token-{$i}"));
    }

    expect(mailsDelivered())->toBe(5);
});

test('nothing goes once the day\'s total is used', function () {
    config(['mail_budget.kinds.account.per_address' => null]);
    $user = User::factory()->create();
    foreach (range(1, 12) as $i) {
        $user->notify(new ResetPassword("token-{$i}"));
    }

    expect(mailsDelivered())->toBe(10);
});

test('passing the warning mark, and holding mail, alert once each', function () {
    config(['mail_budget.kinds.account.per_address' => null]);
    $user = User::factory()->create();
    foreach (range(1, 12) as $i) {
        $user->notify(new ResetPassword("token-{$i}"));
    }

    expect(BureaucracyEscalation::query()->where('kind', 'mail_budget_warning')->count())->toBe(1)
        ->and(BureaucracyEscalation::query()->where('kind', 'mail_held_account')->count())->toBe(1);
});
