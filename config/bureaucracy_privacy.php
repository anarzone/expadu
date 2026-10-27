<?php

return [
    // Product minimisation limits, not statutory retention periods.
    // Bumped when what a request sends changes (22 Oct 2026: Composer's one assistant, with
    // the nearest stop and Veedel, the page and saved places' names; 27 Oct: the names of saved
    // places and events and of favourite stops, when asked about, owner-approved).
    'notice_version' => '2026-10-27.subject-bound-request.11',
    'request_minutes' => 15,
    'audit_days' => 30,
    'max_response_bytes' => 65536,
    // Requests one person may send for AI processing in 24 hours, by purpose. None by default
    // (owner, 22 Oct 2026: "lift the sanctions"); set one with its env, and 0 or empty means none.
    'daily_limits' => [
        'composer_rank' => env('COMPOSER_RANK_DAILY_LIMIT'),
        'composer_assist' => env('COMPOSER_ASSIST_DAILY_LIMIT'),
    ],
];
