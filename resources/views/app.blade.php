<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Cache-Control" content="no-cache">

        {{-- Light only at launch: the page background paints before app.css. --}}
        <meta name="color-scheme" content="light">
        <style>
            html {
                background-color: #faf6ee;
            }
        </style>

        <title inertia>{{ config('app.name', 'Expadu') }}</title>

        <meta name="description" content="Expadu — your smart city companion for life in Germany. Transit, events, bureaucracy help, and local spots for expats in Cologne.">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Open Graph --}}
        <meta property="og:type" content="website">
        <meta property="og:title" content="{{ config('app.name', 'Expadu') }} — Smart City Companion for Expats">
        <meta property="og:description" content="Transit, events, bureaucracy help, and local spots for expats in Cologne.">
        <meta property="og:url" content="{{ config('app.url') }}">
        <meta property="og:image" content="{{ config('app.url') }}/og-image.png">
        <meta property="og:locale" content="en_US">

        <link rel="canonical" href="{{ url()->current() }}">

        {{-- Favicons & app icons (Expadu mark). ?v=1 busts the hard-cached stock icons. --}}
        <link rel="icon" type="image/png" href="/favicon-96x96.png?v=1" sizes="96x96">
        <link rel="icon" type="image/svg+xml" href="/favicon.svg?v=1">
        <link rel="shortcut icon" href="/favicon.ico?v=1">
        <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=1">
        <meta name="apple-mobile-web-app-title" content="Expadu">
        <link rel="manifest" href="/site.webmanifest">
        <meta name="theme-color" content="#faf6ee">

        {{-- Preload only the faces painted above the fold on every app page:
             Geist 400 (body), 600 (nav, and 550 resolves up to it), 700 (page
             h1/h2, and 650 resolves up to it) and Fraunces 500 (wordmark). --}}
        <link rel="preload" href="/fonts/geist-400.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/geist-600.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/geist-700.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/fraunces-500.woff2" as="font" type="font/woff2" crossorigin>

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia

        <script>
            if ('serviceWorker' in navigator) {
                // A worker update can clear stale caches without reloading an
                // open draft or active journey. New page visits load new assets.
                window.addEventListener('load', () => {
                    navigator.serviceWorker
                        .register('/sw.js', { scope: '/' })
                        .then((registration) => {
                            // Re-check for a new deploy whenever the tab regains
                            // focus, so a long-lived tab picks up updates without
                            // a manual refresh.
                            document.addEventListener('visibilitychange', () => {
                                if (document.visibilityState === 'visible') {
                                    registration.update().catch(() => {});
                                }
                            });
                        })
                        .catch(() => {});
                });
            }
        </script>
    </body>
</html>
