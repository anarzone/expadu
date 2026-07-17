<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', 'Expadu — The AI companion for your new life in Germany')</title>
    <meta name="description" content="@yield('meta_description', 'Tell it your day — it plans the rest. Bureaucracy in English with official sources, transit that knows your ticket, and an AI day composer. Built for Cologne.')">
    <link rel="canonical" href="@yield('canonical', url()->current())">

    <meta property="og:site_name" content="Expadu">
    <meta property="og:type" content="website">
    <meta property="og:title" content="@yield('og_title', 'Expadu — The AI companion for your new life in Germany')">
    <meta property="og:description" content="@yield('meta_description', 'Tell it your day — it plans the rest. Bureaucracy in English with official sources, transit that knows your ticket, and an AI day composer. Built for Cologne.')">
    <meta property="og:url" content="@yield('canonical', url()->current())">
    <meta property="og:image" content="@yield('og_image', asset('marketing/og-landing.png'))">
    <meta name="twitter:card" content="summary_large_image">

    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#faf6ee">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#161208">

    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">

    <link rel="preload" href="/fonts/fraunces-500.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/geist-400.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="preload" href="/fonts/geist-600.woff2" as="font" type="font/woff2" crossorigin>

    {{-- Theme before first paint — avoids a light flash for dark-mode visitors --}}
    <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
        (function () {
            var saved = null;
            try { saved = localStorage.getItem('expadu-marketing-theme'); } catch (e) {}
            var dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.classList.toggle('dark', dark);
        })();
    </script>

    @stack('structured-data')
    @stack('head')

    @vite(['resources/css/marketing.css', 'resources/js/marketing.ts'])
    @stack('styles')
</head>
<body class="@yield('body_class')">

<nav class="site">
    <div class="nav-inner">
        <a class="logo" href="{{ route('home') }}" aria-label="Expadu home">
            <svg viewBox="0 0 100 100" aria-hidden="true">
                <g transform="translate(2.45,2.05)">
                    <circle cx="20.5" cy="50" r="6" fill="var(--cyan, #05badd)"/>
                    <rect class="stem" x="30" y="24" width="13" height="52" rx="6"/>
                    <rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/>
                    <rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/>
                    <rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/>
                </g>
            </svg>
            Expadu
        </a>
        <div class="nav-links">
            <a href="{{ route('home') }}#demo">How it works</a>
            <a href="{{ route('home') }}#bureaucracy">Paperwork</a>
            <a href="{{ route('home') }}#transit">Transit</a>
            <a href="{{ route('tools.index') }}">Free tools</a>
            <a href="{{ route('blog.index') }}">Guides</a>
            <a href="{{ route('home') }}#faq">FAQ</a>
        </div>
        <div class="nav-cta">
            <button id="menuBtn" type="button" aria-label="Open menu" aria-expanded="false">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
            <button id="themeToggle" aria-label="Toggle dark mode">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/></svg>
            </button>
            @auth
                <a class="btn btn-primary btn-sm" href="{{ route('dashboard') }}">Open the app →</a>
            @else
                <a class="login" href="{{ route('login') }}">Log in</a>
                <a class="btn btn-primary btn-sm" href="{{ route('register') }}">Start free</a>
            @endauth
        </div>
    </div>
</nav>

@yield('content')

<footer class="site">
    <div class="wrap">
        <div class="foot-grid">
            <div class="waitlist-box">
                <a class="logo footer-logo" href="{{ route('home') }}" aria-label="Expadu home">
                    <svg viewBox="0 0 100 100" aria-hidden="true">
                        <g transform="translate(2.45,2.05)">
                            <circle cx="20.5" cy="50" r="6" fill="var(--cyan, #05badd)"/>
                            <rect class="stem" x="30" y="24" width="13" height="52" rx="6"/>
                            <rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/>
                            <rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/>
                            <rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/>
                        </g>
                    </svg>
                    Expadu
                </a>
                <h3>Not in Cologne?</h3>
                <p>Leave your city — you’ll know the day we arrive.</p>
                <form class="waitlist" id="waitlistForm" method="POST" action="{{ route('waitlist.store') }}">
                    @csrf
                    <input type="hidden" name="source" value="@yield('waitlist_source', 'landing-footer')">
                    <input type="text" name="city" placeholder="Your city" aria-label="Your city" required maxlength="120">
                    <input type="email" name="email" placeholder="Email" aria-label="Email" required maxlength="255">
                    <button class="btn btn-ghost btn-sm" type="submit">Notify me</button>
                    <p class="waitlist-msg" role="status" aria-live="polite"></p>
                </form>
            </div>
            <div class="foot-links">
                <div>
                    <b>Product</b>
                    <a href="{{ route('home') }}#demo">Day composer</a>
                    <a href="{{ route('home') }}#bureaucracy">Bureaucracy</a>
                    <a href="{{ route('home') }}#transit">Transit</a>
                    <a href="{{ route('tools.index') }}">Free tools</a>
                    <a href="{{ route('blog.index') }}">Guides</a>
                </div>
                <div>
                    <b>Account</b>
                    @auth
                        <a href="{{ route('dashboard') }}">Open the app</a>
                    @else
                        <a href="{{ route('login') }}">Log in</a>
                        <a href="{{ route('register') }}">Start free</a>
                    @endauth
                </div>
                <div>
                    <b>Legal</b>
                    <a href="{{ route('impressum') }}">Impressum</a>
                    <a href="{{ route('datenschutz') }}">Datenschutz</a>
                </div>
            </div>
        </div>
        <div class="foot-base">
            <span>© {{ date('Y') }} Expadu · Made in Köln</span>
            <span>Built for Cologne — more cities soon.</span>
        </div>
    </div>
</footer>

@stack('scripts')
</body>
</html>
