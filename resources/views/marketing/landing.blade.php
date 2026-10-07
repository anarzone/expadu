@extends('marketing.layout')

@section('title', 'Expadu — Your new city, joined up')
@section('meta_description', 'Expadu is the daily companion for expats, starting with Cologne: when to leave for the appointment, what to bring and something good for afterwards — in plain English, with paperwork linked to its official sources.')
@section('canonical', route('home'))
@section('body_class', 'xv-body')
@section('own_chrome', '1')

@push('head')
    <link rel="preload" href="/fonts/bricolage-grotesque-latin.woff2" as="font" type="font/woff2" crossorigin>

    {{-- Switch reveal animations on before first paint so content never shows,
         hides and re-appears. If marketing-landing.ts hasn't started within
         4 s (blocked or slow), switch them off again: nothing stays hidden. --}}
    <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
        (function () {
            var html = document.documentElement;
            if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            html.setAttribute('data-xv-anim', 'on');
            setTimeout(function () {
                if (!html.hasAttribute('data-xv-ready')) html.removeAttribute('data-xv-anim');
            }, 4000);
        })();
    </script>
@endpush

@push('styles')
    @vite('resources/css/marketing-landing.css')
@endpush

@push('scripts')
    @vite('resources/js/marketing-landing.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'Organization',
                'name' => 'Expadu',
                'url' => route('home'),
                'logo' => asset('icon-512.png'),
            ],
            [
                '@type' => 'WebSite',
                'name' => 'Expadu',
                'url' => route('home'),
            ],
            [
                '@type' => 'SoftwareApplication',
                'name' => 'Expadu',
                'applicationCategory' => 'TravelApplication',
                'operatingSystem' => 'Web',
                'description' => 'The daily companion for expats, starting with Cologne: appointments with a leave-by time, paperwork linked to official sources, and good things to do afterwards — in plain English.',
                'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
                'url' => route('home'),
            ],
            [
                '@type' => 'FAQPage',
                'mainEntity' => collect($faqs)->map(fn (array $faq): array => [
                    '@type' => 'Question',
                    'name' => $faq['q'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['a']],
                ])->all(),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@php
    $logo = '<g transform="translate(2.45 2.05)"><circle cx="20.5" cy="50" r="6" fill="#05badd"/><rect class="xv-logo__stem" x="30" y="24" width="13" height="52" rx="6"/><rect x="46" y="24" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-7 46 30)"/><rect x="46" y="43.5" width="25" height="13" rx="6" fill="#ff3902" transform="rotate(-4 46 50)"/><rect x="46" y="63" width="34" height="13" rx="6" fill="#ff3902" transform="rotate(-2 46 69)"/></g>';
    $walk = '<circle cx="13" cy="4.5" r="1.8"/><path d="M9.5 21l2.6-6.2 2.6 2.6V21M8.5 11.5l2.4-3.6 3.4 1.4 1.9 3.1M12.1 14.8l-1.2-6.9"/>';
    $check = '<path d="M5 12.5l4.5 4.5L19 7.5"/>';
    $spark = '<path d="M12 2.5c.7 5 3 7.4 8 8.1-5 .7-7.3 3-8 8.1-.7-5.1-3-7.4-8-8.1 5-.7 7.3-3.1 8-8.1z"/>';
    $calendarAlert = '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4M12 13v3M12 18.4v.1"/>';
    $calendarPlus = '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4M12 13v5M9.5 15.5h5"/>';
    $file = '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>';
    $link = '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>';
    $bookmark = '<path d="M7 4h10v17l-5-3.5L7 21z"/>';
    $pin = '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>';
    $arrowRight = '<path d="M5 12h14M13 6l6 6-6 6"/>';
    $arrowDown = '<path d="M12 5v14M6 13l6 6 6-6"/>';
    $clock = '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>';
@endphp

@section('content')
<div class="xv" data-xv>

{{-- ═══════════ 13:10 · Daylight hero ═══════════ --}}
<section id="top" class="xv-band xv-hero" data-clock="13:10|Daylight|#ffe363|hide">
    <svg class="xv-hero__art" data-scrub="out" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
        <g class="xv-hero-sun"><circle cx="1330" cy="110" r="150" fill="#ffb800"/><circle cx="1330" cy="110" r="96" fill="#ff7a00"/></g>
        <g data-reveal="draw" style="transition-delay: 500ms"><path class="r" d="M-20 836 C 300 790, 520 880, 760 820 S 1180 700, 1460 300" stroke="#05badd" stroke-width="16" fill="none" stroke-linecap="round"/></g>
        <g data-reveal="fade" style="transition-delay: 1600ms"><path d="M-20 836 C 300 790, 520 880, 760 820 S 1180 700, 1460 300" stroke="#fff" stroke-width="3" stroke-dasharray="2 14" fill="none" stroke-linecap="round"/></g>
        <g data-reveal="pop" style="transition-delay: 1900ms"><circle cx="760" cy="820" r="22" fill="#05badd" stroke="#0b1a2b" stroke-width="4"/><circle cx="760" cy="820" r="7" fill="#fff"/></g>
        <g class="xv-hide-phone" data-reveal="fade" style="transition-delay: 2300ms"><text class="xv-svg-marker" x="792" y="866" font-size="26" fill="#0b1a2b" transform="rotate(-4 792 866)">you are here</text></g>
    </svg>

    <header class="xv-wrap xv-nav" data-reveal="fade">
        <a class="xv-logo" href="{{ route('home') }}" aria-label="Expadu home">
            <svg viewBox="13 22 74 60" aria-hidden="true">{!! $logo !!}</svg>
            <span class="xv-disp">Expadu</span>
        </a>
        <nav class="xv-nav__links" aria-label="Page sections">
            <a class="xv-navlink" href="#day">The day</a>
            <a class="xv-navlink" href="#composer">Composer</a>
            <a class="xv-navlink" href="#evening">Evenings</a>
            <a class="xv-navlink" href="#trust">Trust</a>
            <a class="xv-navlink" href="{{ route('blog.index') }}">Guides</a>
        </nav>
        <div class="xv-nav__cta">
            @auth
                <a class="xv-btn xv-btn--ink xv-btn--sm" href="{{ route('dashboard') }}">Open the app</a>
            @else
                <a class="xv-login" href="{{ route('login') }}">Log in</a>
                <a class="xv-btn xv-btn--ink xv-btn--sm" href="{{ route('register') }}">Start free</a>
            @endauth
        </div>
    </header>

    <div class="xv-wrap xv-hero__grid">
        <div class="xv-hero__copy">
            <span class="xv-tag" data-reveal="up" style="transition-delay: 100ms"><svg class="xv-ico xv-ico--yellow" viewBox="0 0 24 24" aria-hidden="true">{!! $clock !!}</svg>Wednesday, 13:10 · Cologne</span>
            <h1 class="xv-disp xv-h1">
                <span class="xv-word" data-reveal="word" style="transition-delay: 250ms">Your</span>
                <span class="xv-word" data-reveal="word" style="transition-delay: 420ms">new</span>
                <span class="xv-word" data-reveal="word" style="transition-delay: 590ms">city,</span>
                <span class="xv-word xv-underline" data-reveal="word" style="transition-delay: 850ms">joined up.<svg data-reveal="draw" aria-hidden="true" viewBox="0 0 300 30" preserveAspectRatio="none" style="transition-delay: 1400ms"><path class="r" d="M6 20 C 50 8, 90 26, 140 14 S 230 6, 294 16" stroke="#ff3902" stroke-width="9" fill="none" stroke-linecap="round"/></svg></span>
            </h1>
            <p class="xv-hero__lead" data-reveal="up" style="transition-delay: 1100ms"><strong>Expadu is the daily companion for expats, starting with Cologne.</strong> It knows your day: when to leave for the appointment, what to bring and something good for afterwards. In plain English, with paperwork linked to its official sources.</p>
            <div class="xv-hero__ctas" data-reveal="up" style="transition-delay: 1300ms">
                @auth
                    <a class="xv-btn xv-btn--ink xv-btn--lift-orange" href="{{ route('dashboard') }}">Open the app<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowRight !!}</svg></a>
                @else
                    <a class="xv-btn xv-btn--ink xv-btn--lift-orange" href="{{ route('register') }}">Start free<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowRight !!}</svg></a>
                @endauth
                <a class="xv-btn xv-btn--outline" href="#day">Follow the day<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowDown !!}</svg></a>
            </div>
        </div>

        <div class="xv-stage">
            <article class="xv-poster xv-stage__poster" data-reveal="card" style="transition-delay: 1050ms">
                <svg aria-hidden="true" viewBox="0 0 220 290" preserveAspectRatio="xMidYMid slice"><circle cx="150" cy="86" r="46" fill="#ff7a3d"/><path d="M0 168 Q 27 154 55 168 T 110 168 T 165 168 T 220 168 V 290 H 0 Z" fill="#45208f"/><path d="M0 190 Q 27 176 55 190 T 110 190 T 165 190 T 220 190" stroke="#b79cff" stroke-width="3" fill="none"/></svg>
                <span class="xv-poster__label">Illustration · sample event</span>
                <span class="xv-date">Today 18:30</span>
                <strong class="xv-marker xv-poster__title">Street culture night</strong>
                <span class="xv-poster__place">Rheinauhafen</span>
            </article>

            <div class="xv-today xv-stage__today" data-reveal="card" style="transition-delay: 700ms">
                <div class="xv-today__top">
                    <svg class="xv-today__logo" viewBox="13 22 74 60" aria-hidden="true">{!! $logo !!}</svg>
                    <strong>Your day</strong>
                    <span class="xv-badge">Sample day</span>
                </div>
                <div class="xv-deadline">
                    <svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $calendarAlert !!}</svg>
                    <span><strong>Your D visa expires tomorrow</strong><small>1 Oct · from your records</small></span>
                </div>
                <div class="xv-appt">
                    <div class="xv-appt__time"><strong class="xv-disp">14:00</strong><span>14:30</span></div>
                    <div class="xv-appt__body">
                        <div><strong class="xv-appt__title">Blue Card appointment</strong><span class="xv-appt__meta">Mülheim · Confirmed</span></div>
                        <div class="xv-journey">
                            <div class="xv-note xv-note--leave" data-reveal="write" aria-hidden="true" style="transition-delay: 2300ms"><svg viewBox="0 0 120 40"><path d="M112 32 C 80 36, 42 30, 10 12"/><path d="M24 6 L 8 11 L 19 23"/></svg><span class="xv-marker">leave now!</span></div>
                            <div class="xv-journey__leave"><strong>Leave now</strong><small>13:10 · 12 min to spare</small></div>
                            <div class="xv-journey__line">
                                <span class="xv-linebadge">4</span>
                                <span class="xv-stop"><strong>13:14</strong><small>Neumarkt</small></span>
                                <span class="xv-arrowline" aria-hidden="true"></span>
                                <span class="xv-stop xv-stop--end"><strong>13:42</strong><small>Mülheim</small></span>
                            </div>
                        </div>
                        <div class="xv-bring">
                            <svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $file !!}</svg>
                            <span>What to bring · <span class="xv-circled">1 of 5 ready<svg data-reveal="draw" aria-hidden="true" viewBox="0 0 120 50" preserveAspectRatio="none" style="transition-delay: 2600ms"><path class="r" d="M62 4 C 98 2, 118 14, 116 26 S 92 48, 58 47 S 4 40, 4 25 S 26 3, 70 6" vector-effect="non-scaling-stroke"/></svg></span></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="xv-chat xv-stage__chat" data-reveal="card" style="transition-delay: 1700ms">
                <div class="xv-chat__head"><span class="xv-spark"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg></span><strong>Composer</strong></div>
                <p class="xv-bubble">Anything by the Rhine after my appointment?</p>
                <p class="xv-chat__text">Street culture night at Rheinauhafen starts at 18:30. Shall I add it after your appointment?</p>
                <div class="xv-chat__actions"><span class="xv-pill xv-pill--cyan">Add to plan</span><span class="xv-pill xv-pill--outline">Not now</span></div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ Nine tabs · cool cyan ═══════════ --}}
<section class="xv-band xv-sheet xv-tabs-band" data-clock="13:10|The usual way|#05badd">
    <div class="xv-wrap">
        <div class="xv-head">
            <div class="xv-head__title">
                <span class="xv-tag" data-reveal="up">The usual way</span>
                <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 150ms">Settling in shouldn’t take <span class="xv-highlight">nine open tabs.</span></h2>
            </div>
            <p class="xv-lead xv-lead--teal" data-reveal="up" style="transition-delay: 350ms">The letter is in German. The transit app doesn’t know about the appointment. The events site doesn’t know you’re busy until 14:30.</p>
        </div>

        <div class="xv-tabs-scene" data-reveal="scene">
            <figure class="xv-browser-fig">
                <div class="xv-browser">
                    <div class="xv-browser__dots"><span></span><span></span><span></span></div>
                    <div class="xv-tab9">
                        <span class="xv-t-white">Terminbestätigung_ABH.pdf</span>
                        <span class="xv-t-cyan">KVB Fahrplanauskunft</span>
                        <span class="xv-t-lilac">Blaue Karte EU – Stadt Köln</span>
                        <span class="xv-t-yellow">Was brauche ich für den Termin?</span>
                        <span class="xv-t-peach">Events Köln heute</span>
                        <span class="xv-t-cyan">Mülheim → Rheinauhafen</span>
                        <span class="xv-t-white">Wetter Köln</span>
                        <span class="xv-t-lilac">Notizen: was mitbringen??</span>
                        <span class="xv-t-yellow">Expats Cologne (214)</span>
                    </div>
                    <div class="xv-browser__body xv-before" aria-hidden="true"><span></span><span></span><span></span><span></span><span></span></div>
                </div>
                <figcaption class="xv-marker xv-note xv-note--familiar" data-reveal="write" style="transition-delay: 1600ms">sound familiar?</figcaption>
            </figure>

            <div class="xv-flow" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $arrowRight !!}</svg></div>

            <figure class="xv-browser-fig">
                <div class="xv-browser">
                    <div class="xv-browser__dots"><span></span><span></span><span></span></div>
                    <div class="xv-onetab-row"><span class="xv-onetab"><svg viewBox="13 22 74 60" aria-hidden="true">{!! $logo !!}</svg>Expadu · Your day</span></div>
                    <div class="xv-browser__body">
                        <strong class="xv-disp xv-browser__title">One line, one day.</strong>
                        <div class="xv-afterchips">
                            <span class="xv-c-cyan">13:10 Leave now · Line 4</span>
                            <span class="xv-c-lilac">14:00 Blue Card · 1 of 5 ready</span>
                            <span class="xv-c-yellow">14:30 Ideas near Mülheim</span>
                            <span class="xv-c-peach">18:30 Street culture night</span>
                        </div>
                    </div>
                </div>
            </figure>
        </div>
    </div>
</section>

{{-- ═══════════ 13:10 → 18:30 · Afternoon timeline ═══════════ --}}
<section id="day" class="xv-band xv-sheet xv-day" data-clock="13:10|Afternoon|#05badd">
    <div class="xv-wrap">
        <div class="xv-head">
            <div class="xv-head__title">
                <span class="xv-marker xv-kicker xv-kicker--yellow" data-reveal="write">A day in Expadu</span>
                <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 200ms">One Wednesday in Cologne.</h2>
            </div>
            <p class="xv-lead xv-lead--mist" data-reveal="up" style="transition-delay: 400ms">Follow a sample day. Today, Travel, Paperwork, Events and Composer work from the same plan, so nothing has to be typed twice.</p>
        </div>

        <div class="xv-daylist">
            <div class="xv-dayrow" data-reveal="row" data-clock="13:10|Today|#ffffff">
                <div class="xv-daynum"><strong class="xv-disp">13:10</strong></div>
                <div class="xv-route" aria-hidden="true"><span class="xv-seg xv-seg--first"></span><span class="xv-dot xv-dot--cyan"></span></div>
                <div class="xv-daybody">
                    <span class="xv-role xv-role--white">Today</span>
                    <h3>See what matters first</h3>
                    <p>A deadline from your own records and today’s appointment lead the page. Everything else can wait.</p>
                    <div class="xv-deadline xv-deadline--solo"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $calendarAlert !!}</svg><span><strong>Your D visa expires tomorrow</strong><small>1 Oct · from your records</small></span></div>
                </div>
            </div>

            <div class="xv-dayrow" data-reveal="row" data-clock="13:14|Travel|#05badd">
                <div class="xv-daynum"><strong class="xv-disp xv-daynum--cyan">13:14</strong></div>
                <div class="xv-route" aria-hidden="true"><span class="xv-seg"></span><span class="xv-dot xv-dot--cyan"></span></div>
                <div class="xv-daybody">
                    <span class="xv-role xv-role--cyan">Travel</span>
                    <h3>Leave with time to spare</h3>
                    <p>The walk to the stop is counted. The 13:24 would leave only two minutes to spare, too tight for an official appointment, so Expadu suggests the 13:14.</p>
                    <div class="xv-dayextra">
                        <div class="xv-minitrip"><span class="xv-linebadge">4</span><strong>13:14 Neumarkt</strong><span class="xv-minitrip__line" aria-hidden="true"></span><strong>13:42 Mülheim</strong></div>
                        <span class="xv-marker xv-scribble xv-hide-phone">← 12 min to spare</span>
                    </div>
                </div>
            </div>

            <div class="xv-dayrow" data-reveal="row" data-clock="14:00|Paperwork|#dccbff">
                <div class="xv-daynum"><strong class="xv-disp xv-daynum--lilac">14:00</strong></div>
                <div class="xv-route" aria-hidden="true"><span class="xv-seg"></span><span class="xv-dot xv-dot--lilac"></span></div>
                <div class="xv-daybody">
                    <span class="xv-role xv-role--lilac">Paperwork</span>
                    <h3>Walk in prepared</h3>
                    <p>Your checklist comes with the appointment: what’s ready, what’s missing and the official page behind it.</p>
                    <div class="xv-minicheck">
                        <span class="xv-minicheck__row">What to bring<span class="xv-circled">1 of 5 ready<svg data-reveal="draw" aria-hidden="true" viewBox="0 0 120 50" preserveAspectRatio="none" style="transition-delay: 1400ms"><path class="r" d="M62 4 C 98 2, 118 14, 116 26 S 92 48, 58 47 S 4 40, 4 25 S 26 3, 70 6" vector-effect="non-scaling-stroke"/></svg></span></span>
                        <span class="xv-progress" aria-hidden="true"><span style="width: 20%"></span></span>
                    </div>
                </div>
            </div>

            <div class="xv-dayrow" data-reveal="row" data-clock="14:30|Composer|#05badd">
                <div class="xv-daynum"><strong class="xv-disp">14:30</strong></div>
                <div class="xv-route" aria-hidden="true"><span class="xv-seg"></span><span class="xv-dot xv-dot--hollow"></span></div>
                <div class="xv-daybody">
                    <span class="xv-role xv-role--white">Composer</span>
                    <h3>Ask for the afternoon</h3>
                    <p>“A café near my appointment?” Composer suggests real places from its listings and says what they don’t tell you.</p>
                    <div class="xv-dayextra">
                        <span class="xv-askbubble">A café near my appointment?</span>
                        <span class="xv-gapchip">Listed hours · not a live check</span>
                        <span class="xv-marker xv-scribble xv-hide-phone">so it won’t guess</span>
                    </div>
                </div>
            </div>

            <div class="xv-dayrow xv-dayrow--last" data-reveal="row" data-clock="18:30|Events|#ff7a3d">
                <div class="xv-daynum"><strong class="xv-disp xv-daynum--sun">18:30</strong></div>
                <div class="xv-route" aria-hidden="true"><span class="xv-seg xv-seg--last"></span><span class="xv-dot xv-dot--orange"></span></div>
                <div class="xv-daybody">
                    <span class="xv-role xv-role--orange">Events</span>
                    <h3>End the day somewhere good</h3>
                    <p>Street culture night fits after the appointment. Add it to your plan and the way there comes with it.</p>
                    <a class="xv-textlink" href="#evening">See the evening<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowDown !!}</svg></a>
                </div>
            </div>
        </div>

        <div class="xv-tabbar-wrap" data-reveal="up" data-clock="18:30|One plan|#05badd">
            <p>Five tabs. One plan underneath.</p>
            <div class="xv-tabbar" role="img" aria-label="The five tabs of the Expadu app: Today, Events, Paperwork, Travel and Services">
                <span class="xv-tb-row">
                    <span class="xv-tb-item xv-tb-item--on"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7M5 9v12h14V9M9 21v-8h6v8"/></svg>Today</span>
                    <span class="xv-tb-item"><svg class="xv-tb-violet" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="3"/><path d="M7 2v6m10-6v6M3 11h18m-14 4h3m4 0h3"/></svg>Events</span>
                    <span class="xv-tb-item"><svg class="xv-tb-rust" viewBox="0 0 24 24" aria-hidden="true"><path d="M14 2H5v20h14V7Z"/><path d="M14 2v5h5M8 11h8m-8 4h8m-8 3h5"/></svg>Paperwork</span>
                    <span class="xv-tb-item"><svg class="xv-tb-teal" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="2" width="14" height="17" rx="4"/><path d="M5 11h14M12 2v9M8 22l2-3m6 3-2-3"/></svg>Travel</span>
                    <span class="xv-tb-item"><svg class="xv-tb-violet" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/></svg>Services</span>
                </span>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ Composer · clean white ═══════════ --}}
<section id="composer" class="xv-band xv-sheet xv-composer" data-clock="Any time|Composer|#05badd">
    <div class="xv-wrap xv-cmp-grid">
        <div class="xv-cmp-intro">
            <span class="xv-tag" data-reveal="up"><svg class="xv-ico xv-ico--fill-cyan" viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer · any time</span>
            <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 150ms">Ask in your own words.</h2>
            <p class="xv-lead xv-lead--slate" data-reveal="up" style="transition-delay: 300ms">Composer knows your day, so “after my appointment” means something. It plans with real listings and says “Not listed” instead of guessing.</p>
            <div data-reveal="left" style="transition-delay: 450ms">
                <p class="xv-overline">Try a question</p>
                <div class="xv-prompts" role="group" aria-label="Sample questions">
                    <button type="button" class="xv-prompt" data-q="0" aria-pressed="true" aria-controls="xv-answers"><span>When should I leave for my 14:00?</span><span class="xv-prompt__tag xv-prompt__tag--travel">Travel</span></button>
                    <button type="button" class="xv-prompt" data-q="1" aria-pressed="false" aria-controls="xv-answers"><span>What do I need to bring?</span><span class="xv-prompt__tag xv-prompt__tag--paper">Paperwork</span></button>
                    <button type="button" class="xv-prompt" data-q="2" aria-pressed="false" aria-controls="xv-answers"><span>Anything by the Rhine afterwards?</span><span class="xv-prompt__tag xv-prompt__tag--events">Events</span></button>
                    <button type="button" class="xv-prompt" data-q="3" aria-pressed="false" aria-controls="xv-answers"><span>Is there a pharmacy near my appointment?</span><span class="xv-prompt__tag xv-prompt__tag--places">Places</span></button>
                    <button type="button" class="xv-prompt" data-q="4" aria-pressed="false" aria-controls="xv-answers"><span>Is the evening venue step-free?</span><span class="xv-prompt__tag xv-prompt__tag--gaps">Honest gaps</span></button>
                </div>
                <p class="xv-hint">A sample conversation plays on its own. Tap any question to take over.</p>
            </div>
        </div>

        <div class="xv-window-wrap" data-reveal="card" style="transition-delay: 200ms">
            <div class="xv-note xv-note--knows" data-reveal="write" aria-hidden="true" style="transition-delay: 1300ms"><span class="xv-marker">it says when it doesn’t know</span><svg viewBox="0 0 60 60"><path d="M8 6 C 30 10, 44 26, 40 52"/><path d="M30 42 L 40 54 L 50 42"/></svg></div>
            <div class="xv-window">
                <div class="xv-window__head">
                    <span class="xv-spark xv-spark--lg"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg></span>
                    <strong>Composer</strong>
                    <span class="xv-window__meta">Sample day · Wed 30 Sep, 13:10</span>
                </div>
                <div class="xv-window__body" id="xv-answers" aria-live="polite">
                    <div class="xv-stack xv-stack--end">
                        <p class="xv-q" data-q="0" data-on="1">When should I leave for my 14:00?</p>
                        <p class="xv-q" data-q="1" data-on="0">What do I need to bring?</p>
                        <p class="xv-q" data-q="2" data-on="0">Anything by the Rhine afterwards?</p>
                        <p class="xv-q" data-q="3" data-on="0">Is there a pharmacy near my appointment?</p>
                        <p class="xv-q" data-q="4" data-on="0">Is the evening venue step-free?</p>
                    </div>

                    <div class="xv-stack">
                        <div class="xv-typing" data-on="0" aria-label="Composer is writing"><span></span><span></span><span></span></div>

                        <div class="xv-ans" data-a="0" data-on="1">
                            <p class="xv-disp xv-ans__title">Leave now — it’s 13:10.</p>
                            <ol class="xv-steps">
                                <li><svg class="xv-ico xv-ico--teal" viewBox="0 0 24 24" aria-hidden="true">{!! $walk !!}</svg><span>Walk to Neumarkt</span><strong>4 min</strong></li>
                                <li><span class="xv-linebadge">4</span><span>Line 4 to Mülheim</span><strong>13:14 → 13:42</strong></li>
                                <li><svg class="xv-ico xv-ico--teal" viewBox="0 0 24 24" aria-hidden="true">{!! $walk !!}</svg><span>Walk to the appointment</span><strong>6 min</strong></li>
                            </ol>
                            <p class="xv-muted">You’ll arrive at 13:48 with 12 minutes to spare. I skipped the 13:24 — it would leave you only two.</p>
                            <div><span class="xv-pill xv-pill--outline-ink">Open in Travel<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowRight !!}</svg></span></div>
                        </div>

                        <div class="xv-ans" data-a="1" data-on="0">
                            <p class="xv-disp xv-ans__title">1 of 5 marked ready.</p>
                            <span class="xv-progress" aria-hidden="true"><span style="width: 20%"></span></span>
                            <ul class="xv-checklist">
                                <li><svg class="xv-ico xv-ico--violet" viewBox="0 0 24 24" aria-hidden="true">{!! $check !!}</svg><span>Valid passport and national D visa</span><small class="xv-state xv-state--ready">Ready</small></li>
                                <li><svg class="xv-ico xv-ico--violet" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5"/><path d="M12 4.5a7.5 7.5 0 0 1 0 15z" fill="currentColor"/></svg><span>Employment contract or binding job offer covering at least six months</span><small class="xv-state">You have it</small></li>
                                <li><svg class="xv-ico xv-ico--violet" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5"/></svg><span>Qualification evidence and recognition evidence where required</span><small class="xv-state">Missing</small></li>
                                <li><svg class="xv-ico xv-ico--violet" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5"/></svg><span>Health-insurance evidence</span><small class="xv-state">Missing</small></li>
                                <li><svg class="xv-ico xv-ico--violet" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="7.5" stroke-dasharray="3 3"/></svg><span>Professional licence for a regulated profession, where applicable</span><small class="xv-state">Not sure yet</small></li>
                            </ul>
                            <span class="xv-source"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $link !!}</svg>Stadt Köln · EU Blue Card<span>· Verification recorded 3 Aug 2026 · version 2026-08-03.1</span></span>
                            <p class="xv-small">Sample checklist, not legal advice. Items are the catalogue’s wording, with their official source.</p>
                        </div>

                        <div class="xv-ans" data-a="2" data-on="0">
                            <p class="xv-disp xv-ans__title">One idea fits your evening.</p>
                            <div class="xv-eventrow">
                                <div class="xv-eventrow__art">
                                    <svg aria-hidden="true" viewBox="0 0 132 128" preserveAspectRatio="xMidYMid slice"><circle cx="92" cy="40" r="22" fill="#ff7a3d"/><path d="M0 76 Q 16 68 33 76 T 66 76 T 99 76 T 132 76 V 128 H 0 Z" fill="#45208f"/><path d="M0 92 Q 16 84 33 92 T 66 92 T 99 92 T 132 92" stroke="#b79cff" stroke-width="2" fill="none"/></svg>
                                    <span class="xv-date xv-date--sm">18:30</span>
                                </div>
                                <div class="xv-eventrow__copy">
                                    <strong class="xv-marker">Street culture night</strong>
                                    <span>Rheinauhafen · 18:30–21:00 · Sample event</span>
                                    <span class="xv-fits"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $check !!}</svg>Fits — your plan is free after 14:30</span>
                                </div>
                            </div>
                            <div class="xv-actions"><span class="xv-pill xv-pill--cyan"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $calendarPlus !!}</svg>Add to plan</span><span class="xv-pill xv-pill--outline-ink"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $bookmark !!}</svg>Save</span></div>
                            <p class="xv-small">Adding it isn’t a booking. Composer asks before it changes your plan.</p>
                        </div>

                        <div class="xv-ans" data-a="3" data-on="0">
                            <p class="xv-disp xv-ans__title">Three are listed near Wiener Platz.</p>
                            <p class="xv-muted">Closest first, by straight-line distance from your appointment area. Listed hours aren’t a live check.</p>
                            <ul class="xv-places">
                                <li><span class="xv-mapthumb" aria-hidden="true"><svg viewBox="0 0 56 56"><path d="M0 18 H56 M0 40 H56 M20 0 V56 M44 0 L36 56"/><circle cx="30" cy="27" r="6"/></svg></span><span><small>Pharmacy · Mülheim · 50 m</small><strong>Eck-Apotheke</strong><small>Listed Wed 08:30–18:30 · Wheelchair access listed</small></span></li>
                                <li><span class="xv-mapthumb" aria-hidden="true"><svg viewBox="0 0 56 56"><path d="M0 14 H56 M0 36 H56 M14 0 V56 M38 0 L46 56"/><circle cx="26" cy="25" r="6"/></svg></span><span><small>Pharmacy · Mülheim · 150 m</small><strong>Einhorn-Apotheke</strong><small>Listed Wed 08:00–19:00 · Wheelchair access listed</small></span></li>
                                <li><span class="xv-mapthumb" aria-hidden="true"><svg viewBox="0 0 56 56"><path d="M0 22 H56 M0 44 H56 M24 0 V56 M8 0 L16 56"/><circle cx="34" cy="31" r="6"/></svg></span><span><small>Pharmacy · Mülheim · 200 m</small><strong>Brücken-Apotheke</strong><small>Listed Wed 08:30–19:00 · Wheelchair access listed</small></span></li>
                            </ul>
                            <div class="xv-actions"><span class="xv-pill xv-pill--outline-ink"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $pin !!}</svg>Show on map</span><span class="xv-small">From OpenStreetMap, observed 1 Oct 2026</span></div>
                        </div>

                        <div class="xv-ans" data-a="4" data-on="0">
                            <p class="xv-disp xv-ans__title">Not listed.</p>
                            <p class="xv-muted">Neither the event nor a venue listing records step-free access, so I won’t guess. It’s worth checking with the organiser before you go.</p>
                            <dl class="xv-facts">
                                <div><dt>Accessibility</dt><dd>Not listed</dd></div>
                                <div><dt>Admission</dt><dd>Not listed</dd></div>
                                <div><dt>Starts</dt><dd class="xv-known">18:30</dd></div>
                            </dl>
                        </div>
                    </div>
                </div>
                <div class="xv-window__foot">
                    <div class="xv-fakeinput"><span>Sample conversation — pick a question to see the answer</span><span class="xv-send" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 19V5M6 11l6-6 6 6"/></svg></span></div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ═══════════ 18:30 · Dusk / Events ═══════════ --}}
<section id="evening" class="xv-band xv-sheet xv-evening" data-clock="18:30|Evening|#ff7a3d">
    <div class="xv-wrap">
        <div class="xv-head">
            <div class="xv-head__title">
                <span class="xv-tag xv-tag--yellow" data-reveal="pop">18:30 · Evening</span>
                <h2 class="xv-disp xv-h2 xv-h2--xl"><span class="xv-word" data-reveal="word" style="transition-delay: 150ms">Then,</span> <span class="xv-marker xv-goodpart" data-reveal="write" style="transition-delay: 500ms">the good part.</span></h2>
            </div>
            <p class="xv-lead xv-lead--lilac" data-reveal="up" style="transition-delay: 300ms">Events are good things to turn up for, with the details in plain English. They fit around your plans, and the way there comes with them.</p>
        </div>

        <div class="xv-events">
            <article class="xv-poster xv-poster--big" data-reveal="card">
                <svg data-scrub="through" aria-hidden="true" viewBox="0 0 1200 600" preserveAspectRatio="xMidYMid slice">
                    <rect width="1200" height="600" fill="#3a2160"/>
                    <rect width="1200" height="240" fill="#2b1d3f"/>
                    <g class="xv-ev-sun"><circle cx="840" cy="330" r="150" fill="#ff7a3d"/><g fill="#3a2160"><rect x="680" y="344" width="320" height="8"/><rect x="680" y="366" width="320" height="11"/><rect x="680" y="392" width="320" height="14"/><rect x="680" y="420" width="320" height="18"/></g></g>
                    <path d="M40 150 Q 270 240 520 150" stroke="#6b4fb0" stroke-width="2" fill="none"/>
                    <g class="xv-ev-lights" fill="#ffe363"><circle cx="80" cy="168" r="5"/><circle cx="140" cy="188" r="5"/><circle cx="200" cy="200" r="5"/><circle cx="260" cy="205" r="5"/><circle cx="320" cy="202" r="5"/><circle cx="380" cy="191" r="5"/><circle cx="440" cy="176" r="5"/><circle cx="500" cy="157" r="5"/></g>
                    <g fill="#1d1236"><rect x="110" y="370" width="90" height="80"/><rect x="200" y="338" width="64" height="112"/><rect x="520" y="360" width="84" height="90"/><rect x="604" y="384" width="56" height="66"/><path d="M290 450 V320 H312 V220 L323 210 L335 80 L347 210 L358 220 V280 L390 260 L422 280 V220 L433 210 L445 80 L457 210 L468 220 V320 H490 V450 Z"/></g>
                    <rect y="450" width="1200" height="70" fill="#4a35a0"/>
                    <g fill="#ff7a3d" opacity="0.75"><rect x="770" y="466" width="140" height="5" rx="2.5"/><rect x="790" y="484" width="100" height="5" rx="2.5"/><rect x="812" y="502" width="56" height="5" rx="2.5"/></g>
                    <g class="xv-ev-train"><rect x="700" y="412" width="240" height="28" rx="12" fill="#05badd"/><g fill="#ffe363"><rect x="716" y="419" width="22" height="10" rx="3"/><rect x="748" y="419" width="22" height="10" rx="3"/><rect x="780" y="419" width="22" height="10" rx="3"/><rect x="812" y="419" width="22" height="10" rx="3"/><rect x="844" y="419" width="22" height="10" rx="3"/><rect x="876" y="419" width="22" height="10" rx="3"/></g></g>
                    <rect x="560" y="440" width="700" height="10" fill="#45208f"/>
                    <path d="M560 446 Q 680 372 800 446 Q 920 372 1040 446 Q 1160 372 1280 446" stroke="#8a6ad8" stroke-width="8" fill="none"/>
                    <path d="M0 520 Q 300 492 600 520 T 1200 514 V 600 H 0 Z" fill="#170d2b"/>
                    <g fill="#0b0618"><circle cx="1010" cy="474" r="12"/><path d="M994 522 Q 996 490 1010 488 Q 1024 490 1026 522 Z"/><circle cx="1046" cy="478" r="11"/><path d="M1031 522 Q 1033 494 1046 492 Q 1059 494 1061 522 Z"/></g>
                </svg>
                <span class="xv-poster__label xv-poster__label--right">Illustration · sample event</span>
                <span class="xv-date xv-date--lg" data-reveal="pop" style="transition-delay: 600ms">Today 18:30</span>
                <strong class="xv-marker xv-poster__title xv-poster__title--xl" data-reveal="write" style="transition-delay: 800ms">Street culture night</strong>
                <span class="xv-poster__place xv-poster__place--lg" data-reveal="up" style="transition-delay: 1100ms">Rheinauhafen · until 21:00</span>
                <div class="xv-poster__actions" data-reveal="up" style="transition-delay: 1300ms">
                    <a class="xv-btn xv-btn--cyan xv-btn--md" href="{{ route('register') }}"><svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $calendarPlus !!}</svg>Add to plan</a>
                    <span class="xv-marker xv-scribble xv-hide-phone">fits after your appointment!</span>
                </div>
            </article>

            <article class="xv-poster xv-poster--cinema" data-reveal="card" style="transition-delay: 300ms">
                <svg aria-hidden="true" viewBox="0 0 400 600" preserveAspectRatio="xMidYMid slice">
                    <g fill="#fff"><circle cx="40" cy="50" r="2"/><circle cx="120" cy="30" r="1.5"/><circle cx="200" cy="70" r="2.2"/><circle cx="300" cy="40" r="1.6"/><circle cx="360" cy="90" r="2"/><circle cx="80" cy="120" r="1.4"/><circle cx="250" cy="130" r="1.8"/><circle cx="340" cy="170" r="1.2"/></g>
                    <circle cx="320" cy="110" r="34" fill="#ffe363"/><circle cx="334" cy="100" r="30" fill="#0f3a42"/>
                    <path d="M200 470 L 70 196 L 330 196 Z" fill="#ffe363" opacity="0.16"/>
                    <g stroke="#041a1e" stroke-width="6"><path d="M92 336 V 430 M308 336 V 430"/></g>
                    <rect x="56" y="172" width="288" height="168" rx="6" fill="#1d0f33"/>
                    <rect x="64" y="180" width="272" height="152" rx="3" fill="#ffb15c"/>
                    <circle cx="252" cy="258" r="34" fill="#ff7a3d"/>
                    <path d="M64 300 Q 140 262 210 296 T 336 290 V 332 H 64 Z" fill="#45208f"/>
                    <g fill="#1d0f33"><circle cx="146" cy="274" r="9"/><path d="M134 312 Q 136 288 146 286 Q 156 288 158 312 Z"/><circle cx="176" cy="278" r="8"/><path d="M165 312 Q 167 292 176 290 Q 185 292 187 312 Z"/></g>
                    <path d="M0 420 Q 200 380 400 420 V 600 H 0 Z" fill="#082a30"/>
                    <g fill="#041a1e"><circle cx="110" cy="404" r="14"/><circle cx="160" cy="400" r="14"/><circle cx="210" cy="404" r="14"/><circle cx="260" cy="400" r="14"/></g>
                </svg>
                <span class="xv-poster__label">Illustration · sample event</span>
                <span class="xv-date xv-date--md" data-reveal="pop" style="transition-delay: 900ms">Today 20:00</span>
                <strong class="xv-marker xv-poster__title xv-poster__title--lg" data-reveal="write" style="transition-delay: 1100ms">Cinema under the stars</strong>
                <span class="xv-poster__place xv-poster__place--md">Stadtgarten</span>
            </article>
        </div>
    </div>
</section>

{{-- ═══════════ Your city · lilac poster map ═══════════ --}}
<section id="cologne" class="xv-band xv-sheet xv-city" data-clock="18:30|Your city|#dccbff">
    <div class="xv-wrap xv-head">
        <div class="xv-head__title">
            <span class="xv-marker xv-kicker xv-kicker--orange" data-reveal="write">Starting with Cologne</span>
            <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 200ms">Built in Cologne, by an expat who had to figure it out first.</h2>
        </div>
        <div class="xv-city__copy" data-reveal="up" style="transition-delay: 400ms">
            <p class="xv-lead xv-lead--violet">Expadu started with scattered official pages, German-only notices and missed context. We’re building it for Cologne first, district by district, and designing it to grow city by city.</p>
            <ul class="xv-pills">
                <li>In plain English, with the German where it matters</li>
                <li>KVB and VRS timetables</li>
                <li>Stadt Köln paperwork, explained</li>
            </ul>
        </div>
    </div>
    <figure class="xv-map">
        <svg data-scrub="enter" data-span="1.1" viewBox="300 60 760 520" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Illustrative map of Cologne showing the sample day: from Neumarkt by Line 4 to the Blue Card appointment in Mülheim, then to Rheinauhafen in the evening">
            <path class="xv-map-streets" d="M-900 200 H2300 M-900 330 H2300 M-900 470 H2300 M-500 -200 V800 M-200 -200 V800 M180 -200 V800 M380 -200 V800 M560 -200 L 520 800 M960 -200 V800 M1080 -200 L 1120 800 M1400 -200 V800 M1700 -200 V800 M2000 -200 V800"/>
            <path d="M760 660 C 700 540, 600 490, 640 380 S 820 240, 770 140 S 830 30, 900 -40" stroke="#05badd" stroke-width="96" fill="none" stroke-linecap="round"/>
            <path d="M760 660 C 700 540, 600 490, 640 380 S 820 240, 770 140 S 830 30, 900 -40" stroke="#9eeefd" stroke-width="3" stroke-dasharray="3 14" fill="none"/>
            <text x="664" y="566" font-size="18" font-weight="700" fill="#005466" letter-spacing="6" transform="rotate(-62 664 566)">RHEIN</text>
            <g class="xv-map-label" font-size="22" font-weight="600" fill="#4b2a8f"><text x="316" y="276">Ehrenfeld</text><text x="360" y="180">Neustadt-Nord</text><text x="840" y="410">Deutz</text></g>
            <path fill="#2a0f5c" d="M560 330 V290 H566 V262 L569 259 L573 228 L577 259 L580 262 V278 L590 272 L600 278 V262 L603 259 L607 228 L611 259 L614 262 V290 H620 V330 Z"/>
            <text class="xv-map-dom" x="570" y="352" font-size="15" font-weight="700" fill="#2a0f5c">Dom</text>
            <path class="xv-map-route" d="M480 400 C 560 392, 620 380, 680 362 S 800 312, 830 250 S 880 176, 920 150" stroke="#ff3902" stroke-width="12" fill="none" stroke-linecap="round"/>
            <path class="xv-map-eve" d="M920 150 C 880 270, 780 430, 610 486" stroke="#2a0f5c" stroke-width="4" stroke-dasharray="10 10" fill="none" stroke-linecap="round"/>
            <g class="xv-pin xv-pin--you"><circle cx="480" cy="400" r="30" fill="#05badd" stroke="#0b1a2b" stroke-width="5"/><circle cx="480" cy="400" r="10" fill="#fff"/><text class="xv-svg-marker" x="340" y="460" font-size="30" fill="#0b1a2b" transform="rotate(-4 340 460)">you are here</text><text class="xv-map-label" x="330" y="388" font-size="24" font-weight="700" fill="#0b1a2b">Neumarkt</text></g>
            <g class="xv-pin xv-pin--m"><circle cx="920" cy="150" r="22" fill="#7047ac" stroke="#fff" stroke-width="5"/><text class="xv-map-label" x="962" y="86" text-anchor="middle" font-size="24" font-weight="700" fill="#0b1a2b">Mülheim</text><text class="xv-map-label xv-map-sm" x="962" y="110" text-anchor="middle" font-size="18" fill="#4b2a8f">14:00 · Blue Card</text></g>
            <g class="xv-pin xv-pin--r"><circle cx="610" cy="486" r="22" fill="#ffe363" stroke="#0b1a2b" stroke-width="5"/><text class="xv-map-label" x="372" y="530" font-size="24" font-weight="700" fill="#0b1a2b">Rheinauhafen</text><text class="xv-map-label xv-map-sm" x="372" y="556" font-size="18" fill="#4b2a8f">18:30 · Street culture night</text></g>
        </svg>
        <figcaption class="xv-wrap">Illustrative map, not to scale · sample day</figcaption>
    </figure>
</section>

{{-- ═══════════ 22:00 · Night / Trust ═══════════ --}}
<section id="trust" class="xv-band xv-sheet xv-trust" data-clock="22:00|Night|#dccbff">
    <svg class="xv-trust__sky" data-scrub="enter" data-span="0.8" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
        <g class="xv-stars" fill="#fff"><circle cx="120" cy="90" r="2"/><circle cx="260" cy="40" r="1.5"/><circle cx="420" cy="120" r="1.8"/><circle cx="700" cy="60" r="1.4"/><circle cx="940" cy="110" r="2"/><circle cx="1180" cy="50" r="1.6"/><circle cx="1320" cy="240" r="2.2"/><circle cx="1040" cy="200" r="1.2"/><circle cx="560" cy="180" r="1.3"/></g>
        <g class="xv-moon"><circle cx="1300" cy="120" r="40" fill="#ffe363"/><circle cx="1318" cy="106" r="36" fill="#0b1a2b"/></g>
    </svg>
    <div class="xv-wrap xv-relative">
        <div class="xv-head">
            <div class="xv-head__title">
                <span class="xv-tag xv-tag--lilac" data-reveal="pop">22:00 · Night</span>
                <h2 class="xv-disp xv-h2 xv-h2--xl" data-reveal="up" style="transition-delay: 150ms">Honest by design.</h2>
            </div>
            <p class="xv-lead xv-lead--fog" data-reveal="up" style="transition-delay: 300ms">A new country is uncertain enough. Expadu shows where facts come from, and when it doesn’t know, it says so.</p>
        </div>
        <div class="xv-tcards">
            <div class="xv-tcard" data-reveal="up">
                <span class="xv-ticon xv-ticon--cyan"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $link !!}</svg></span>
                <h3>Every figure has a source</h3>
                <p>In Paperwork, a fee or deadline appears only with an official source and the date its verification was recorded. No source, no figure.</p>
            </div>
            <div class="xv-tcard" data-reveal="up" style="transition-delay: 180ms">
                <span class="xv-ticon xv-ticon--yellow"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.4M12 17v.1"/></svg></span>
                <h3>Unknown stays “Not listed”</h3>
                <p>If a listing doesn’t record opening hours or step-free access, Expadu tells you instead of guessing.</p>
            </div>
            <div class="xv-tcard" data-reveal="up" style="transition-delay: 360ms">
                <span class="xv-ticon xv-ticon--lilac"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 14l2 2 4-4"/></svg></span>
                <h3>Built on the official rules</h3>
                <p>Every Paperwork step is tied to the law and the city’s own page it comes from, linked so you can check. It never decides eligibility for you, and it isn’t legal advice.</p>
            </div>
            <div class="xv-tcard" data-reveal="up" style="transition-delay: 540ms">
                <span class="xv-ticon xv-ticon--orange"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="2.5"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/></svg></span>
                <h3>Nothing happens without your OK</h3>
                <p>Composer asks before it changes your plan. Expadu never books, pays or submits anything for you.</p>
            </div>
        </div>
        <p class="xv-sources" data-reveal="up" style="transition-delay: 300ms">
            <span>Where our facts come from:</span>
            <a href="https://www.stadt-koeln.de" rel="noopener">stadt-koeln.de</a>
            <a href="https://www.make-it-in-germany.com" rel="noopener">make-it-in-germany.com</a>
            <a href="https://www.gesetze-im-internet.de" rel="noopener">gesetze-im-internet.de</a>
            <a href="https://www.vrs.de" rel="noopener">VRS timetable data</a>
            <a href="https://www.openstreetmap.org" rel="noopener">OpenStreetMap</a>
        </p>
        <div class="xv-quote">
            <p class="xv-marker" data-reveal="write" style="transition-delay: 200ms">Showing nothing beats guessing.</p>
            <span data-reveal="fade" style="transition-delay: 1400ms">The rule behind every Expadu screen.</span>
        </div>
    </div>
</section>

{{-- ═══════════ 23:00 · Good to know ═══════════ --}}
<section id="faq" class="xv-band xv-sheet xv-faq" data-clock="23:00|Good to know|#ffffff">
    <div class="xv-wrap xv-faq__grid">
        <div class="xv-faq__main">
            <span class="xv-tag" data-reveal="up">23:00 · Good to know</span>
            <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 150ms">Questions before you sleep on it.</h2>
            <div class="xv-faq__list" data-reveal="up" style="transition-delay: 300ms">
                @foreach ($faqs as $faq)
                    <details @if ($loop->first) open @endif>
                        <summary>{{ $faq['q'] }}</summary>
                        <p>{{ $faq['a'] }}</p>
                    </details>
                @endforeach
            </div>
        </div>
        <aside class="xv-faq__side" data-reveal="up" style="transition-delay: 450ms" aria-label="Useful before you sign up">
            <p class="xv-overline">Useful before you sign up</p>
            <a class="xv-linkcard xv-linkcard--yellow" href="{{ route('tools.dticket') }}"><small>Free tool</small><strong>Deutschlandticket break-even</strong><span>Is it worth it for how you actually move?</span></a>
            <a class="xv-linkcard xv-linkcard--cyan" href="{{ route('tools.netto') }}"><small>Free tool</small><strong>Netto-brutto calculator</strong><span>See what actually lands in your account.</span></a>
            <a class="xv-linkcard xv-linkcard--lilac" href="{{ route('blog.show', 'anmeldung-in-cologne-english-guide') }}"><small>Guide · 8 min read</small><strong>Anmeldung in Cologne, in English</strong><span>The documents, the landlord form and what comes after.</span></a>
            <a class="xv-linkcard xv-linkcard--peach" href="{{ route('blog.show', 'first-90-days-in-cologne-in-order') }}"><small>Guide · 10 min read</small><strong>Your first 90 days in Cologne, in order</strong><span>What depends on what, from address to tax.</span></a>
            <p class="xv-faq__more"><a href="{{ route('tools.index') }}">All free tools</a> · <a href="{{ route('blog.index') }}">All guides</a></p>
        </aside>
    </div>
</section>

{{-- ═══════════ Next morning · Join ═══════════ --}}
<section id="join" class="xv-band xv-sheet xv-join" data-clock="Thu 08:00|Next morning|#ffe363">
    <svg class="xv-join__sun" data-scrub="enter" data-span="0.9" data-ref="parent" aria-hidden="true" viewBox="0 0 600 600"><circle cx="300" cy="300" r="290" fill="#ff6a00"/><circle cx="300" cy="300" r="210" fill="#ffb800"/><circle cx="300" cy="300" r="140" fill="#ffe363"/></svg>
    <div class="xv-wrap xv-join__grid">
        <div class="xv-join__copy">
            <span class="xv-tag" data-reveal="pop">Next morning</span>
            <h2 class="xv-disp xv-h2 xv-h2--join"><span class="xv-word" data-reveal="word" style="transition-delay: 150ms">Feel</span> <span class="xv-word" data-reveal="word" style="transition-delay: 300ms">at</span> <span class="xv-word" data-reveal="word" style="transition-delay: 450ms">home</span> <span class="xv-word xv-white" data-reveal="word" style="transition-delay: 700ms">sooner.</span></h2>
            <p class="xv-lead" data-reveal="up" style="transition-delay: 900ms">Tell Expadu your situation once. Your day, your paperwork and your evenings stay joined up from there.</p>
            <div class="xv-join__ctas" data-reveal="up" style="transition-delay: 1100ms">
                @auth
                    <a class="xv-btn xv-btn--ink xv-btn--lg xv-btn--lift-yellow" href="{{ route('dashboard') }}">Open the app<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowRight !!}</svg></a>
                @else
                    <a class="xv-btn xv-btn--ink xv-btn--lg xv-btn--lift-yellow" href="{{ route('register') }}">Start free in Cologne<svg class="xv-ico" viewBox="0 0 24 24" aria-hidden="true">{!! $arrowRight !!}</svg></a>
                @endauth
                <span class="xv-marker xv-join__note">Free to start · in plain English</span>
            </div>
        </div>
        <form class="xv-form waitlist" id="waitlistForm" method="POST" action="{{ route('waitlist.store') }}" data-reveal="card" style="transition-delay: 500ms">
            @csrf
            <input type="hidden" name="source" value="landing-join">
            <div>
                <h3 class="xv-disp">Not in Cologne yet?</h3>
                <p>Tell us where you’re moving. We’ll write once when Expadu reaches your city.</p>
            </div>
            <label>Email<input type="email" name="email" autocomplete="email" placeholder="you@example.com" required maxlength="255"></label>
            <label>City<input type="text" name="city" autocomplete="address-level2" placeholder="For example, Munich" required maxlength="120"></label>
            <button class="xv-btn xv-btn--cyan xv-btn--block" type="submit">Notify me</button>
            <p class="waitlist-msg" role="status" aria-live="polite"></p>
        </form>
    </div>
</section>

{{-- ═══════════ Footer ═══════════ --}}
<footer class="xv-footer">
    <div class="xv-wrap">
        <div class="xv-footer__grid">
            <div class="xv-footer__brand">
                <a class="xv-logo xv-logo--light" href="{{ route('home') }}" aria-label="Expadu home">
                    <svg viewBox="13 22 74 60" aria-hidden="true">{!! $logo !!}</svg>
                    <span class="xv-disp">Expadu</span>
                </a>
                <p>The daily companion for life in a new city. Starting with Cologne.</p>
            </div>
            <nav class="xv-footer__links" aria-label="Footer">
                <div>
                    <strong>Product</strong>
                    <a href="#day">Today</a>
                    <a href="#evening">Events</a>
                    <a href="#day">Paperwork</a>
                    <a href="#day">Travel</a>
                    <a href="#composer">Composer</a>
                </div>
                <div>
                    <strong>Learn</strong>
                    <a href="{{ route('blog.index') }}">Field guide</a>
                    <a href="{{ route('tools.index') }}">Free tools</a>
                    <a href="#trust">How we check facts</a>
                    <a href="#faq">FAQ</a>
                </div>
                <div>
                    <strong>Account</strong>
                    @auth
                        <a href="{{ route('dashboard') }}">Open the app</a>
                    @else
                        <a href="{{ route('login') }}">Log in</a>
                        <a href="{{ route('register') }}">Start free</a>
                    @endauth
                </div>
                <div>
                    <strong>Legal</strong>
                    <a href="{{ route('impressum') }}">Impressum</a>
                    <a href="{{ route('datenschutz') }}">Datenschutz</a>
                </div>
            </nav>
        </div>
        <div class="xv-footer__base">
            <span>© {{ date('Y') }} Expadu · Made in Cologne</span>
            <span>Times, events, places and checklists on this page are sample content; event pictures and the map are illustrations. Expadu isn’t legal advice. Place data © OpenStreetMap contributors.</span>
        </div>
    </div>
</footer>

{{-- The day clock follows the story; the script only sets attributes. --}}
<div class="xv-clock" aria-hidden="true">
    <span class="xv-clock__dot"><svg viewBox="0 0 24 24">{!! $clock !!}</svg></span>
    <span class="xv-clock__text"><strong class="xv-disp xv-clock__time" data-v="13:10"></strong><small class="xv-clock__label" data-v="Daylight"></small></span>
</div>

</div>
@endsection
