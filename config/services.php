<?php

// Owner, 21 Oct 2026: every AI feature runs on Claude Haiku, never Sonnet or Opus.
// A *_MODEL env value names a Haiku version; anything else falls back to Haiku 5.5.
$haiku = fn (mixed $model): string => is_string($model) && str_starts_with($model, 'claude-haiku-') ? $model : 'claude-haiku-5-5';

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
        'model' => $haiku(env('ANTHROPIC_MODEL')),
        // low | medium | high. The classifier forces its tool, so this
        // mostly trims output spend rather than thinking.
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
    ],

    // Transit notices (KVB, VRS, DELFI) as short English briefs (App\Transit\NoticeBriefWriter).
    // Public data only. Off without ANTHROPIC_API_KEY or with the switch.
    'transit_briefs' => [
        'enabled' => env('TRANSIT_BRIEFS_ENABLED', true),
        'model' => $haiku(env('TRANSIT_BRIEFS_MODEL')),
        'effort' => env('TRANSIT_BRIEFS_EFFORT', 'low'),
        'daily_limit' => (int) env('TRANSIT_BRIEFS_DAILY_LIMIT', 300),
        'prompt_version' => '2026-10-22',
    ],

    // Place descriptions and evidence-backed vibe tags (App\Places\Enrichment).
    // Public OSM data only. Off by default; uses ANTHROPIC_API_KEY. The
    // daily budget covers sync calls, collected batch results and the
    // estimate for batches still open; a submission is cut to fit it.
    'place_enrichment' => [
        'enabled' => env('PLACE_ENRICHMENT_ENABLED', false),
        'model' => $haiku(env('PLACE_ENRICHMENT_MODEL')),
        'effort' => env('PLACE_ENRICHMENT_EFFORT', 'low'),
        'max_tokens' => (int) env('PLACE_ENRICHMENT_MAX_TOKENS', 1024),
        'daily_budget_usd' => (float) env('PLACE_ENRICHMENT_DAILY_BUDGET_USD', 3),
        'batch_size' => (int) env('PLACE_ENRICHMENT_BATCH_SIZE', 2000),
        'sync_concurrency' => (int) env('PLACE_ENRICHMENT_SYNC_CONCURRENCY', 3),
        'prompt_version' => '2026-10-21.1',
    ],

    // The nightly taste profile (App\Personalisation\TasteProfileWriter):
    // 3–5 sentences from bucketed facts, for people who opted in to
    // personalisation. Off unless switched on; a daily cap on calls.
    'taste_profile' => [
        'enabled' => env('TASTE_PROFILE_ENABLED', false),
        'model' => $haiku(env('TASTE_PROFILE_MODEL')),
        'effort' => env('TASTE_PROFILE_EFFORT', 'low'),
        'daily_limit' => (int) env('TASTE_PROFILE_DAILY_LIMIT', 500),
        'prompt_version' => '2026-10-21.taste.1',
    ],

    'composer_llm' => [
        'enabled' => env('COMPOSER_LLM_ENABLED', false),
        'key' => env('COMPOSER_LLM_KEY') ?: env('ANTHROPIC_API_KEY'),
        'model' => $haiku(env('COMPOSER_LLM_MODEL')),
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
        // Not used by the app (Haiku only); priced so evals comparing models report cost.
        'claude-sonnet-5-5' => ['input_per_mtok' => 2.00, 'output_per_mtok' => 10.00],
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
        'model' => $haiku(env('LLM_MODEL')),
        'effort' => env('LLM_EFFORT', 'low'),
        'key' => env('LLM_KEY') ?: env('ANTHROPIC_API_KEY'),
        'processor_name' => env('LLM_PROCESSOR_NAME', 'Anthropic PBC'),
        'processor_privacy_url' => env('LLM_PROCESSOR_PRIVACY_URL', 'https://www.anthropic.com/legal/privacy'),
        // Composer's assistant: one loop for every message (it looks things up with tools, then answers).
        'prompt_version' => '2026-10-22.assistant.2',
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

    // gtfs.de's free DELFI feeds (CC BY-SA 4.0, attribution required, no
    // guarantee): Travel reads only the cancellations VRS leaves out. The
    // realtime ids match gtfs.de's own static feed, so a slice of it (the
    // area below) is imported to place them.
    'gtfsde' => [
        'static_url' => env('GTFSDE_STATIC_URL', 'https://download.gtfs.de/germany/nv_free/latest.zip'),
        'realtime_url' => env('GTFSDE_REALTIME_URL', 'https://realtime.gtfs.de/realtime-free.pb'),
        // south, west, north, east: Cologne.
        'area' => [50.83, 6.77, 51.09, 7.17],
    ],

    'kvb' => [
        // The last good stop list, kept under storage/ for a cold cache; empty keeps none (tests).
        'stops_file' => env('KVB_STOPS_FILE', 'app/kvb-stops.json'),
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
