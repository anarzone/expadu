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
        'queue_lease_minutes' => 30,
        'active_interval_seconds' => 7 * 24 * 60 * 60,
        'failure_retry_seconds' => [60 * 60, 6 * 60 * 60, 24 * 60 * 60, 7 * 24 * 60 * 60],
    ],

    'acquisition' => [
        'claim_minutes' => 30,
        'outcome_cooldown_days' => 30,
        'transient_retry_seconds' => [60 * 60, 6 * 60 * 60, 24 * 60 * 60],
    ],

    'providers' => [
        // The structured event feed does not grant reusable image rights. The
        // city's copyright notice requires prior written permission and bars
        // commercial reuse, so validation may prove health but never rights.
        'stadt-koeln' => [
            'hosts' => ['www.stadt-koeln.de'],
        ],
        'koeln-de' => [
            'hosts' => ['www.koeln.de'],
        ],
        'wikimedia-commons' => [
            'hosts' => ['commons.wikimedia.org', 'thumb.wikimedia.org', 'upload.wikimedia.org'],
        ],
        'koeln-tourismus' => [
            'hosts' => ['dam.destination.one'],
        ],
        // Mapillary documents its imagery as CC BY-SA 4.0. Its thumbnail URLs
        // use Meta CDN shards; each host must be audited and listed exactly.
        'mapillary' => [
            'hosts' => ['scontent-mxp1-1.xx.fbcdn.net', 'z-p3-scontent.xx.fbcdn.net', 'mapillary.com'],
        ],
    ],

    'open_licenses' => [
        'CC0',
        'Public domain',
        'PD',
        'CC BY',
        'CC BY-SA',
    ],

    'mapillary' => [
        // Free client token from mapillary.com/dashboard/developers. Without it
        // the resolver no-ops, so the command stays safe to schedule.
        'token' => env('MAPILLARY_TOKEN'),
        // A street-level photo is only OF a place if the camera was pointing at
        // it. Beyond this many degrees off the bearing to the spot, the frame
        // shows whatever was across the road instead.
        'max_bearing_offset_degrees' => (int) env('MAPILLARY_MAX_BEARING_OFFSET', 40),
        // Keep the search box local enough that bearing is useful evidence.
        'radius_metres' => (int) env('MAPILLARY_RADIUS', 30),
    ],
];
