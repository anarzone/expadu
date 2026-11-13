<?php

use App\Mail\ConfirmationCode;
use App\Mail\UnverifiedAccountReminder;
use App\Mail\WaitlistConfirmation;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;

/*
|--------------------------------------------------------------------------
| Daily email budget (App\Security\MailBudget)
|--------------------------------------------------------------------------
|
| Every email passes MailBudget before it's handed to the mailer. The
| provider sends a fixed number a day for everything (Resend's free plan:
| 100), so each kind has its own share and account mails come first: people
| can't sign up, sign in or reset a password without them. A mail over its
| kind's share, over the day's total, or to an address that already had its
| share today isn't sent. An alert goes out when the day passes `warn_at` of
| the total, and when any mail is held.
|
*/

return [
    'daily' => (int) env('MAIL_DAILY_LIMIT', 100),

    'warn_at' => 0.8,

    'kinds' => [
        // No share of their own: they take whatever the other kinds leave.
        'account' => [
            'daily' => null,
            'per_address' => 5,
            'classes' => [VerifyEmail::class, ResetPassword::class, ConfirmationCode::class, UnverifiedAccountReminder::class],
        ],
        // Two per address: the website form mails once a day itself; the
        // Paperwork "tell me when it's covered" list may re-send once.
        'waitlist' => [
            'daily' => 40,
            'per_address' => 2,
            'classes' => [WaitlistConfirmation::class],
        ],
        // Anything else.
        'other' => [
            'daily' => 20,
            'per_address' => null,
            'classes' => [],
        ],
    ],
];
