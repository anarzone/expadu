<?php

return [
    'user_agent' => 'Expadu/1.0 (media validation; contact: support@expadu.com)',

    'validation' => [
        'connect_timeout_seconds' => 5,
        'timeout_seconds' => 15,
        'max_bytes' => 10 * 1024 * 1024,
        'min_width' => 400,
        'min_height' => 225,
        'broken_after_failures' => 3,
    ],

    'providers' => [
        'stadt-koeln' => [
            'hosts' => ['www.stadt-koeln.de'],
        ],
        'koeln-de' => [
            'hosts' => ['www.koeln.de'],
        ],
        'wikimedia-commons' => [
            'hosts' => ['commons.wikimedia.org', 'upload.wikimedia.org'],
        ],
    ],

    'open_licenses' => [
        'CC0',
        'Public domain',
        'PD',
        'CC BY',
        'CC BY-SA',
    ],
];
