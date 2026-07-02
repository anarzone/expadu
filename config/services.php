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

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
    ],

    /*
     * The composer's prompt parser. 'heuristic' (default) needs no key and
     * runs the product today; set driver to 'openai' with a key to route
     * through any OpenAI-compatible chat API (DeepSeek or OpenAI/GPT — same
     * base URL + model knobs). The parser only classifies and parses.
     */
    'llm' => [
        'driver' => env('LLM_DRIVER', 'heuristic'),
        'base_url' => env('LLM_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('LLM_MODEL', 'deepseek-chat'),
        'key' => env('LLM_KEY'),
    ],

    'motis' => [
        // Our self-hosted MOTIS (VRS GTFS+RT + NRW OSM). Primary routing
        // provider; the public Transitous below is the dev/failover backstop.
        // Defaults to the local benchmark instance for development.
        'url' => env('MOTIS_URL', 'http://localhost:8080'),
    ],

    'transitous' => [
        'url' => env('TRANSITOUS_URL', 'https://api.transitous.org'),
    ],

    /*
     * Live appointment availability from the city's Smart CJM booking
     * system (termine.stadt-koeln.de). Off by default: robots.txt asks not
     * to be crawled, so checks stay explicit (slots:check) and unscheduled
     * until a posture is agreed with the city.
     */
    'smartcjm' => [
        'enabled' => env('SMARTCJM_SLOTS_ENABLED', false),
    ],

    'vrs' => [
        'gtfsrt_url' => env('VRS_GTFSRT_URL'),
        'trias_url' => env('VRS_TRIAS_URL'),
        'client_cert' => env('VRS_CLIENT_CERT'),
        'client_cert_password' => env('VRS_CLIENT_CERT_PASSWORD'),
        'enabled' => env('VRS_REALTIME_ENABLED', false),
        'requestor_ref' => env('VRS_REQUESTOR_REF', 'expadu'),
    ],

];
