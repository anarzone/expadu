<?php

/*
 * Feature flags for pages whose backend is incomplete and which would
 * otherwise dead-end users. Set the corresponding env var to true once the
 * page is launch-ready; the sidebar entry and route both auto-unhide.
 */
return [
    'language_exchange' => (bool) env('FEATURE_LANGUAGE_EXCHANGE', false),
    'chat' => (bool) env('FEATURE_CHAT', false),
    'neighbourhoods' => (bool) env('FEATURE_NEIGHBOURHOODS', false),
    'just_arrived' => (bool) env('FEATURE_JUST_ARRIVED', false),
];
