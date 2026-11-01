@php
    // Appearance, per device (resources/js/lib/appearance.ts): Light or Dark from
    // the cookie paints at once; System is settled by the script below, before
    // the first paint, from the device's own setting.
    $appearance = in_array(request()->cookie('appearance'), ['light', 'dark'], true)
        ? request()->cookie('appearance')
        : 'system';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => $appearance === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta http-equiv="Cache-Control" content="no-cache">

        {{-- The page background paints before app.css, in the look it will have. --}}
        <meta name="color-scheme" content="{{ $appearance === 'system' ? 'light dark' : $appearance }}">
        @if ($appearance === 'system')
            <script>
                if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            </script>
        @endif
        <style>
            html {
                background-color: #faf6ee;
            }
            html.dark {
                background-color: #18170f;
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
        @if ($appearance === 'system')
            <meta name="theme-color" content="#faf6ee" media="(prefers-color-scheme: light)">
            <meta name="theme-color" content="#18170f" media="(prefers-color-scheme: dark)">
        @else
            <meta name="theme-color" content="{{ $appearance === 'dark' ? '#18170f' : '#faf6ee' }}">
        @endif

        @php
            // Only pages inside the app shell load its chunk and CSS; sign-in
            // and onboarding are outside it (the same list as
            // resources/js/lib/resolve-page.tsx).
            $inShell = ! preg_match('/^auth\//', $page['component']);
            $viteEntries = [
                'resources/js/app.tsx',
                ...($inShell ? ['resources/js/layouts/app-shell-layout.tsx'] : []),
                "resources/js/pages/{$page['component']}.tsx",
            ];
            // Pages whose first screen sets a heading in Fraunces 500.
            $firstScreenFraunces = in_array($page['component'], ['alerts', 'profile'], true);
        @endphp

        {{-- Preload only the faces painted above the fold: Geist 400 (body),
             600 (nav, and 550 resolves up to it) and 700 (page h1/h2, and 650
             resolves up to it) everywhere, Fraunces 500 only where a first-
             screen heading uses it. --}}
        <link rel="preload" href="/fonts/geist-400.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/geist-600.woff2" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="/fonts/geist-700.woff2" as="font" type="font/woff2" crossorigin>
        @if ($firstScreenFraunces)
            <link rel="preload" href="/fonts/fraunces-500.woff2" as="font" type="font/woff2" crossorigin>
        @endif

        @viteReactRefresh
        @vite($viteEntries)
        @inertiaHead
    </head>
    <body class="font-sans">
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
