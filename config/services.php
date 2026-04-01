<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'valhalla' => [
        'url' => env('VALHALLA_URL'),
    ],

    'vrs' => [
        'gtfsrt_url' => env('VRS_GTFSRT_URL', 'https://gtfs-rt-test.vrs.de:4443/buffer/tripUpdate.buf'),
        'trias_url' => env('VRS_TRIAS_URL', 'https://apitest.vrs.de:4443/v1/trias'),
        'ca_cert' => env('VRS_CA_CERT', base_path('VRS-CA.cer')),
        'enabled' => env('VRS_REALTIME_ENABLED', false),
        'requestor_ref' => env('VRS_REQUESTOR_REF', 'expadu'),
    ],

];
