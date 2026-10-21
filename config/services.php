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
        // The Resend SDK builds its Guzzle client with no timeout, so
        // AppServiceProvider rebinds it with these.
        'connect_timeout' => 5,
        'timeout' => 15,
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
        // Socialite passes this to Guzzle for the token and profile calls.
        'guzzle' => [
            'connect_timeout' => 5,
            'timeout' => 10,
        ],
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => env('APPLE_REDIRECT_URI'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-5-5'),
        // low | medium | high. The classifier forces its tool, so this
        // mostly trims output spend rather than thinking.
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
    ],

    // KVB notices as short English briefs (App\Transit\NoticeBriefWriter).
    // Public data only. Off without ANTHROPIC_API_KEY or with the switch.
    'transit_briefs' => [
        'enabled' => env('TRANSIT_BRIEFS_ENABLED', true),
        'model' => env('TRANSIT_BRIEFS_MODEL', 'claude-haiku-5-5'),
        'effort' => env('TRANSIT_BRIEFS_EFFORT', 'low'),
        'daily_limit' => (int) env('TRANSIT_BRIEFS_DAILY_LIMIT', 300),
        'prompt_version' => '2026-10-21',
    ],

    'composer_llm' => [
        'enabled' => env('COMPOSER_LLM_ENABLED', false),
        'key' => env('COMPOSER_LLM_KEY') ?: env('ANTHROPIC_API_KEY'),
        'model' => env('COMPOSER_LLM_MODEL', 'claude-haiku-5-5'),
        // Thinking is disabled for latency, which Haiku 5.5 accepts only at
        // effort high or below; xhigh/max would 400 and fall back.
        'effort' => env('COMPOSER_LLM_EFFORT', 'low'),
        'timeout' => env('COMPOSER_LLM_TIMEOUT', 8),
        // Share of users (stable per user) whose plans the LLM ranks; the rest
        // get the deterministic ranker, so the two can be compared.
        'rollout_percent' => (int) env('COMPOSER_LLM_ROLLOUT_PERCENT', 100),
        'processor_name' => env('COMPOSER_LLM_PROCESSOR_NAME'),
        'processor_privacy_url' => env('COMPOSER_LLM_PROCESSOR_PRIVACY_URL'),
        'prompt_version' => '2026-09-08.ranking.1',
    ],

    /*
     * Prices per million tokens, used only to fill llm_calls.cost_usd. Leave a
     * model out rather than guess: unpriced calls record a null cost. Example:
     * 'claude-haiku-5-5' => ['input_per_mtok' => 0.0, 'output_per_mtok' => 0.0],
     */
    'llm_pricing' => [
        // Prompts up to 100K tokens; longer ones bill at $0.50 / $2.50.
        'claude-haiku-5-5' => ['input_per_mtok' => 0.10, 'output_per_mtok' => 0.50],
    ],

    /*
     * The composer's prompt parser. 'heuristic' (default) needs no key and
     * runs the product today; set driver to 'anthropic' to have Claude read
     * the request, with the heuristic as the fallback. The parser only
     * classifies and parses.
     */
    'llm' => [
        'driver' => env('LLM_DRIVER', 'heuristic'),
        'base_url' => env('LLM_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('LLM_MODEL', 'claude-haiku-5-5'),
        'effort' => env('LLM_EFFORT', 'low'),
        'key' => env('LLM_KEY') ?: env('ANTHROPIC_API_KEY'),
        'processor_name' => env('LLM_PROCESSOR_NAME', 'Anthropic PBC'),
        'processor_privacy_url' => env('LLM_PROCESSOR_PRIVACY_URL', 'https://www.anthropic.com/legal/privacy'),
        'prompt_version' => '2026-10-21.parser.claude.2',
    ],

    'bureaucracy_llm' => [
        'enabled' => env('BUREAUCRACY_LLM_ENABLED', false),
        'base_url' => env('BUREAUCRACY_LLM_BASE_URL', 'https://api.anthropic.com'),
        'model' => env('BUREAUCRACY_LLM_MODEL', 'claude-haiku-5-5'),
        'effort' => env('BUREAUCRACY_LLM_EFFORT', 'low'),
        'key' => env('BUREAUCRACY_LLM_KEY') ?: env('ANTHROPIC_API_KEY'),
        'processor_name' => env('BUREAUCRACY_LLM_PROCESSOR_NAME', 'Anthropic PBC'),
        'processor_privacy_url' => env('BUREAUCRACY_LLM_PROCESSOR_PRIVACY_URL', 'https://www.anthropic.com/legal/privacy'),
        'timeout' => (int) env('BUREAUCRACY_LLM_TIMEOUT', 8),
        'prompt_version' => env('BUREAUCRACY_LLM_PROMPT_VERSION', '2026-10-21.claude.1'),
        'daily_limit' => (int) env('BUREAUCRACY_LLM_DAILY_LIMIT', 20),
    ],

    'motis' => [
        // Our self-hosted MOTIS (VRS GTFS+RT + NRW OSM). Primary routing
        // provider on staging/production. Leave it unset locally so the public
        // Transitous adapter below is used without probing the Laravel app.
        'url' => env('MOTIS_URL'),
    ],

    'transitous' => [
        'url' => env('TRANSITOUS_URL', 'https://api.transitous.org'),
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
