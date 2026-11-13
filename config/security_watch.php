<?php

/*
|--------------------------------------------------------------------------
| Security watch (App\Security\SecurityWatch)
|--------------------------------------------------------------------------
|
| security:watch runs every ten minutes and counts the last hour of the
| security log. When a count reaches its mark, the admins get an email and
| the error reporter an alert, once per rule per day. Real traffic runs at a
| few sign-ups a day, so these marks only trip on a flood.
|
| security:daily-summary emails the admins the last 24 hours each morning.
|
*/

return [
    'hourly' => [
        // New accounts (web, iPhone, Google, Apple) and waitlist sign-ups.
        'signups' => (int) env('SECURITY_WATCH_SIGNUPS', 20),
        // Posts the bot traps, the throwaway-domain check or a mail cap turned away.
        'blocked' => (int) env('SECURITY_WATCH_BLOCKED', 30),
        // Wrong passwords and wrong second-step codes.
        'failed_logins' => (int) env('SECURITY_WATCH_FAILED_LOGINS', 30),
        // Tries refused by the rate limits on the sign-in forms.
        'throttled' => (int) env('SECURITY_WATCH_THROTTLED', 20),
    ],

    // Share of the day's email budget (config/mail_budget.php) that alerts.
    'mail_used' => 0.8,
];
