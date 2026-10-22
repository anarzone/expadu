<?php

return [
    // Product minimisation limits, not statutory retention periods.
    'notice_version' => '2026-09-08.subject-bound-request.2',
    'request_minutes' => 15,
    'audit_days' => 30,
    'max_response_bytes' => 65536,
    'daily_limits' => [
        // Every Composer message is one read (about $0.0006 with Haiku): 20 a day ran out in an
        // hour of real use (owner's account, 22 Oct 2026), and past it Composer reads without AI.
        'composer_parse' => (int) env('COMPOSER_PARSE_DAILY_LIMIT', 200),
        'composer_rank' => 20,
    ],
];
