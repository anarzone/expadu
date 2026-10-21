<?php

return [
    // Product minimisation limits, not statutory retention periods.
    'notice_version' => '2026-09-08.subject-bound-request.2',
    'request_minutes' => 15,
    'audit_days' => 30,
    'max_response_bytes' => 65536,
    'daily_limits' => [
        'composer_parse' => 20,
        'composer_rank' => 20,
    ],
];
