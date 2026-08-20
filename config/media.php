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
        // Street-level imagery, CC BY-SA 4.0, free for commercial use. Photos
        // are served from Facebook's CDN (Meta owns Mapillary), so the host
        // allow-list has to cover the scontent shards, not graph.mapillary.com.
        'mapillary' => [
            'hosts' => ['scontent-mxp1-1.xx.fbcdn.net', 'z-p3-scontent.xx.fbcdn.net', 'fbcdn.net', 'mapillary.com'],
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
        // Mapillary caps radius at 50m.
        'radius_metres' => (int) env('MAPILLARY_RADIUS', 30),
    ],
];
