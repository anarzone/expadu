<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Expadu — The AI companion for your new life in Germany')</title>
    <meta name="description" content="@yield('meta_description', 'Tell it your day — it plans the rest. Bureaucracy in English with official sources, transit that knows your ticket, and an AI day composer. Built for Cologne.')">
    {{-- Not url()->current(): in production that follows the forced app root, and the app host 404s these paths. --}}
    @php($canonicalFallback = rtrim(config('app.marketing_domain') ? 'https://'.config('app.marketing_domain') : url('/'), '/').'/'.ltrim(request()->path(), '/'))
    <link rel="canonical" href="@yield('canonical', $canonicalFallback)">

    <meta property="og:site_name" content="Expadu">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@section('og_title')@yield('title', 'Expadu — Tell it your day. It plans the rest.')@show">
    <meta property="og:description" content="@yield('meta_description', 'Tell it your day — it plans the rest. Bureaucracy in English with official sources, transit that knows your ticket, and an AI day composer. Built for Cologne.')">
    <meta property="og:url" content="@yield('canonical', $canonicalFallback)">
    <meta property="og:image" content="@yield('og_image', asset('marketing/og-landing.png'))">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    <meta name="theme-color" content="@yield('theme_color', '#ffe363')">

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/geist-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/geist-600.woff2" as="font" type="font/woff2" crossorigin>

    @stack('structured-data')
    @stack('head')

    @vite(['resources/css/marketing.css', 'resources/js/marketing.ts'])
    @stack('styles')
</head>
<body class="@yield('body_class')">

{{-- Every page shares the landing's header and footer. The landing draws them
     inside its own sections ("own_chrome"); other pages get a coloured band
     ("tone") with their title ("hero") above a white page, and the launch
     waitlist above the footer. --}}
@sectionMissing('own_chrome')
<div class="mk-band" data-tone="@yield('tone', 'yellow')">
    <svg class="mk-band__art" aria-hidden="true" viewBox="0 0 1440 200" preserveAspectRatio="none"><path d="M-20 170 C 300 140, 560 196, 820 150 S 1240 60, 1460 40" stroke="#05badd" stroke-width="10" fill="none" stroke-linecap="round"/><path d="M-20 170 C 300 140, 560 196, 820 150 S 1240 60, 1460 40" stroke="#fff" stroke-width="2" stroke-dasharray="2 12" fill="none" stroke-linecap="round"/></svg>
    <div class="xv xv-site">
        @include('marketing.partials.header')
    </div>
    @hasSection('hero')
        <div class="mk-hero">
            @yield('hero')
        </div>
    @endif
</div>
<div class="mk-page">
    @yield('content')
</div>
<div class="xv">
    @include('marketing.partials.waitlist', ['source' => trim($__env->yieldContent('waitlist_source', 'footer'))])
    @include('marketing.partials.footer')
</div>
@else
    @yield('content')
@endif

@stack('scripts')
</body>
</html>
