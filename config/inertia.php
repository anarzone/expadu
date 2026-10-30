<?php

return [

    // Private page props must become unreadable after logout on shared devices.
    // On everywhere deployed. It needs a secure context (https or localhost), so
    // the local preview turns it off to be reached over plain http from a phone.
    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Side Rendering
    |--------------------------------------------------------------------------
    |
    | These options configures if and how Inertia uses Server Side Rendering
    | to pre-render each initial request made to your application's pages
    | so that server rendered HTML is delivered for the user's browser.
    |
    | See: https://inertiajs.com/server-side-rendering
    |
    */

    'ssr' => [
        // A second local server (another worktree) turns it off or points at its own
        // SSR, so it never renders with another checkout's code.
        'enabled' => (bool) env('INERTIA_SSR_ENABLED', true),
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        // Seconds. A page that misses this renders client-side instead of
        // holding a PHP-FPM worker (Laravel's HTTP default is 30 s).
        'timeout' => (float) env('INERTIA_SSR_TIMEOUT', 1.5),
        'connect_timeout' => (float) env('INERTIA_SSR_CONNECT_TIMEOUT', 0.5),
        // 'bundle' => base_path('bootstrap/ssr/ssr.mjs'),

    ],

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | The values described here are used to locate Inertia components on the
    | filesystem. For instance, when using `assertInertia`, the assertion
    | attempts to locate the component as a file relative to the paths.
    |
    */

    'testing' => [

        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
