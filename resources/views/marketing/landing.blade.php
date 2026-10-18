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
    {{-- Blade would read the schema.org context key as one of its own directives, so the key is escaped with a double at sign. --}}
    <script type="application/ld+json">{!! json_encode([
        '@@context' => 'https://schema.org',
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
    $spark = '<path d="M12 2.5c.7 5 3 7.4 8 8.1-5 .7-7.3 3-8 8.1-.7-5.1-3-7.4-8-8.1 5-.7 7.3-3.1 8-8.1z"/>';
    $calendarPlus = '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4M12 13v5M9.5 15.5h5"/>';
    $file = '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>';
    $link = '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>';
    $pin = '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>';
    $arrowRight = '<path d="M5 12h14M13 6l6 6-6 6"/>';
    $arrowDown = '<path d="M12 5v14M6 13l6 6 6-6"/>';
    $clock = '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>';
@endphp

@section('content')
<div class="xv" data-xv>

{{-- "Cologne in motion" (18 Oct 2026): colour follows a sample Wednesday; the
     app's own screens (real captures from the design preview, labelled as
     sample) play the day on one pinned phone. --}}

{{-- ═══════════ 13:10 · Daylight hero ═══════════ --}}
<section id="top" class="xv-band xv-hero" data-clock="13:10|Daylight|#ffe363|hide">
    <svg class="xv-hero__art" data-scrub="out" aria-hidden="true" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice">
        <g class="xv-hero-sun"><circle cx="1330" cy="110" r="150" fill="#ffb800"/><circle cx="1330" cy="110" r="96" fill="#ff7a00"/></g>
        <g data-reveal="draw" style="transition-delay: 500ms"><path id="xv-hero-route" class="r" d="M-20 836 C 300 790, 520 880, 760 820 S 1180 700, 1460 300" stroke="#05badd" stroke-width="16" fill="none" stroke-linecap="round"/></g>
        <g data-reveal="fade" style="transition-delay: 1600ms"><path d="M-20 836 C 300 790, 520 880, 760 820 S 1180 700, 1460 300" stroke="#fff" stroke-width="3" stroke-dasharray="2 14" fill="none" stroke-linecap="round"/></g>
        <g data-reveal="pop" style="transition-delay: 1900ms"><circle cx="760" cy="820" r="22" fill="#05badd" stroke="#0b1a2b" stroke-width="4"/><circle cx="760" cy="820" r="7" fill="#fff"/></g>
        <g class="xv-hide-phone" data-reveal="fade" style="transition-delay: 2300ms"><text class="xv-svg-marker" x="792" y="866" font-size="26" fill="#0b1a2b" transform="rotate(-4 792 866)">you are here</text></g>
        {{-- Tram 4 sets off along the route once the phone has landed (started by marketing-landing.ts). --}}
        <g class="xv-hero-tram" data-reveal="fade" style="transition-delay: 2000ms">
            <g>
                <rect x="-38" y="-15" width="76" height="30" rx="12" fill="#a01e5a" stroke="#0b1a2b" stroke-width="3"/>
                <rect x="-28" y="-8" width="14" height="9" rx="2.5" fill="#ffe363"/><rect x="-9" y="-8" width="14" height="9" rx="2.5" fill="#ffe363"/><rect x="10" y="-8" width="14" height="9" rx="2.5" fill="#ffe363"/>
                <animateMotion dur="9s" begin="indefinite" fill="freeze" rotate="auto" keyPoints="0;0.62" keyTimes="0;1" calcMode="spline" keySplines=".45 0 .25 1"><mpath href="#xv-hero-route"/></animateMotion>
            </g>
        </g>
    </svg>

    <header class="xv-wrap xv-nav" data-reveal="fade">
        <a class="xv-logo" href="{{ route('home') }}" aria-label="Expadu home">
            <svg viewBox="13 22 74 60" aria-hidden="true">{!! $logo !!}</svg>
            <span class="xv-disp">Expadu</span>
        </a>
        <nav class="xv-nav__links" aria-label="Page sections">
            <a class="xv-navlink" href="#day">The day</a>
            <a class="xv-navlink" href="#street">Events</a>
            <a class="xv-navlink" href="#composer">Composer</a>
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

        <div class="xv-hero-stage">
            <img class="xv-hero-poster" data-reveal="card" style="transition-delay: 1150ms" src="{{ asset('marketing/landing/poster-street.webp') }}" alt="Sample poster from Expadu Events: Street culture night, tonight 18:30 at Rheinauhafen" width="586" height="808">
            <figure class="xv-hero-phone" data-reveal="card" style="transition-delay: 700ms">
                <div class="xv-dev">
                    <div class="xv-dev__screen">
                        <img class="xv-full" src="{{ asset('marketing/landing/scr-today.webp') }}" alt="Expadu Today on a phone, sample day: D visa expires tomorrow; 14:00 Blue Card appointment in Mülheim; 1 of 5 documents ready; Leave now, tram 4 at 13:14 from Neumarkt, there 13:48, 12 minutes spare" width="780" height="1688">
                        <span class="xv-tap xv-tap--hero" aria-hidden="true"></span>
                    </div>
                </div>
            </figure>
            <div class="xv-hero-note" data-reveal="write" aria-hidden="true" style="transition-delay: 2300ms">
                <svg viewBox="0 0 54 30"><path d="M52 22 C 38 26, 20 22, 6 12"/><path d="M15 6 L 4 11 L 12 21"/></svg>
                <span class="xv-marker">12 min to spare!</span>
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
                            <span class="xv-c-white">13:10 Leave now · <em class="xv-kvb xv-kvb--sm">4</em> 13:14</span>
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

{{-- ═══════════ The day · one pinned phone, colour follows the time ═══════════
     The keyframes run on the scroll timeline of this section (CSS only, off the
     main thread). Where scroll timelines aren't supported, or motion is reduced,
     marketing-landing.ts steps through the same keyframes instead. --}}
<section id="day" class="xv-band xv-sheet xv-story" data-mode="glide">
    <div class="xv-story__marks" aria-hidden="true">
        <div style="--a: 0; --b: 0.18" data-clock="13:10|Today|#ffe363"></div>
        <div style="--a: 0.18; --b: 0.4" data-clock="13:14|Travel|#e0408f"></div>
        <div style="--a: 0.4; --b: 0.59" data-clock="14:00|Paperwork|#dccbff"></div>
        <div style="--a: 0.59; --b: 0.75" data-clock="18:30|Events|#ff7a3d"></div>
        <div id="street" style="--a: 0.75; --b: 1" data-clock="18:30|On the street|#ff7a3d"></div>
    </div>

    <div class="xv-pin">
        <div class="xv-bgl xv-bg1 xv-k" aria-hidden="true"></div>
        <div class="xv-bgl xv-bg2 xv-k" aria-hidden="true"></div>
        <div class="xv-bgl xv-bg3 xv-k" aria-hidden="true"></div>
        <div class="xv-glow xv-k" aria-hidden="true"></div>

        <div class="xv-wrap xv-pin__grid">
            <div class="xv-pin__text">
                <div class="xv-rail xv-k" aria-hidden="true">
                    <ol>
                        <li class="xv-rl0 xv-k"><b>13:10</b> Today</li>
                        <li class="xv-rl1 xv-k"><b>13:14</b> Travel</li>
                        <li class="xv-rl2 xv-k"><b>14:00</b> Paperwork</li>
                        <li class="xv-rl3 xv-k"><b>18:30</b> Events</li>
                    </ol>
                    <span class="xv-rail__track"><span class="xv-rail__fill xv-k"></span></span>
                </div>

                <div class="xv-caps">
                    <article class="xv-cap xv-c0 xv-k">
                        <strong class="xv-disp xv-cap__time">13:10</strong>
                        <span class="xv-cap__tag xv-cap__tag--today">Today</span>
                        <h3>Your day, in order of what matters.</h3>
                        <p>The visa deadline from your own records comes first. Then the 14:00 appointment, what to bring, and the tram that gets you there with 12 minutes to spare.</p>
                    </article>
                    <article class="xv-cap xv-c1 xv-k">
                        <strong class="xv-disp xv-cap__time">13:14</strong>
                        <span class="xv-cap__tag xv-cap__tag--travel">Travel</span>
                        <h3>On your way, step by step.</h3>
                        <p>Walk four minutes to Neumarkt, take tram 4 from the underground platform, get off at Wiener Platz at 13:42. It even shows where to stand, from OpenStreetMap’s platform data.</p>
                    </article>
                    <article class="xv-cap xv-c2 xv-k">
                        <strong class="xv-disp xv-cap__time">14:00</strong>
                        <span class="xv-cap__tag xv-cap__tag--paper">Paperwork</span>
                        <h3>Walk in prepared.</h3>
                        <p>Your EU Blue Card checklist comes with the appointment: what’s ready and what’s still to get, in plain English, with the German where it matters.</p>
                    </article>
                    <article class="xv-cap xv-c3 xv-k">
                        <strong class="xv-disp xv-cap__time">18:30</strong>
                        <span class="xv-cap__tag xv-cap__tag--events">Events</span>
                        <h3>Then, the good part.</h3>
                        <p>Tonight’s programme, pasted on a Cologne wall. Keep scrolling and step outside.</p>
                    </article>
                </div>
            </div>

            <div class="xv-devslot">
                <div class="xv-dev xv-dev--stage xv-k">
                    <div class="xv-dev__screen">
                        <div class="xv-scr xv-s0 xv-k"><img class="xv-full" src="{{ asset('marketing/landing/scr-today.webp') }}" alt="Expadu Today, sample day: Leave now for the 14:00 Blue Card appointment" width="780" height="1688" decoding="async"><span class="xv-tap xv-tap--go xv-k" aria-hidden="true"></span></div>
                        <div class="xv-scr xv-s1 xv-k">
                            <div class="xv-trip-body"><img class="xv-trip-body__img xv-k" src="{{ asset('marketing/landing/trip-body.webp') }}" alt="Expadu Travel, sample trip: walk to Neumarkt, then tram 4 at 13:14 from Gleis 2 underground; your way to Wiener Platz and the appointment" width="780" height="1966" decoding="async"></div>
                            <img class="xv-trip-head" src="{{ asset('marketing/landing/trip-head.webp') }}" alt="" width="780" height="196" decoding="async">
                        </div>
                        <div class="xv-scr xv-s2 xv-k"><img class="xv-full" src="{{ asset('marketing/landing/scr-paper.webp') }}" alt="Expadu Paperwork, sample records: EU Blue Card documents, 1 of 5 ready" width="780" height="1688" decoding="async"></div>
                        <div class="xv-scr xv-s3 xv-k"><img class="xv-full" src="{{ asset('marketing/landing/scr-events.webp') }}" alt="Expadu Events, sample programme: tonight’s posters on a Cologne street" width="780" height="1688" decoding="async"></div>
                        <span class="xv-fly xv-k" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="14" rx="4"/><path d="M5 10h14M12 3v7M9 20l1.5-3M15 20l-1.5-3"/></svg><b>4</b></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- The phone's Events screen opens into the street. --}}
        <div class="xv-portal xv-k">
            <div class="xv-scene xv-k">
                <img class="xv-scene__img" src="{{ asset('marketing/landing/street.webp') }}" alt="" width="2400" height="1251" decoding="async">
                <span class="xv-wtag xv-t1 xv-k" aria-hidden="true">18:00</span>
                <span class="xv-wtag xv-t2 xv-k" aria-hidden="true">20:00</span>
                <img class="xv-wposter xv-p1 xv-k" src="{{ asset('marketing/landing/poster-street.webp') }}" alt="Sample poster: Street culture night, tonight 30 Sept, 18:30 until 21:00, Rheinauhafen, price not listed" width="586" height="808" decoding="async">
                <img class="xv-wposter xv-p2 xv-k" src="{{ asset('marketing/landing/poster-cinema.webp') }}" alt="Sample poster: Cinema under the stars, tonight 30 Sept, 20:00 until 22:00, Stadtgarten, rain likely (sample forecast)" width="582" height="806" decoding="async">
            </div>
            <div class="xv-rain" aria-hidden="true"><span></span><span></span></div>
            <div class="xv-weather xv-k" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M7 15a4 4 0 0 1 .5-8 5.5 5.5 0 0 1 10.4 1.6A3.3 3.3 0 0 1 17.5 15z"/><path d="M8 18l-1 2M12 18l-1 2M16 18l-1 2"/></svg><span><b>17° Showers</b><small>18:30 · Sample weather</small></span></div>
            <div class="xv-portal__head xv-k">
                <h2 class="xv-disp">Good things to turn up for.</h2>
                <p>A curated programme with the details in plain English. Plan to go, and the way there comes with it.</p>
            </div>
            <span class="xv-portal__label xv-k">Sample programme · the street and posters from Expadu’s Events page</span>
        </div>
    </div>
</section>

{{-- ═══════════ Composer · the brightest part ═══════════ --}}
<section id="composer" class="xv-band xv-sheet xv-cmp" data-clock="Any time|Composer|#05badd">
    <div class="xv-wrap xv-cmp__grid">
        <div class="xv-cmp__copy">
            <span class="xv-tag" data-reveal="up"><svg class="xv-ico xv-ico--fill-cyan" viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer · any time</span>
            <h2 class="xv-disp xv-h2" data-reveal="up" style="transition-delay: 150ms">Ask in your own words.</h2>
            <p class="xv-lead xv-lead--slate" data-reveal="up" style="transition-delay: 300ms">Composer knows your plan, uses the city’s listings and says “Not listed” instead of guessing. It asks before it changes your plan, unless you let it make small edits you can undo. It never books or pays.</p>
            <p class="xv-cmp__hint" data-reveal="up" style="transition-delay: 450ms">A sample conversation plays on its own. Tap any card to take over.</p>
        </div>

        <div class="xv-cwin-wrap" data-reveal="card" style="transition-delay: 200ms">
            <div class="xv-note xv-note--knows" data-reveal="write" aria-hidden="true" style="transition-delay: 1300ms"><span class="xv-marker">it says when it doesn’t know</span><svg viewBox="0 0 60 60"><path d="M8 6 C 30 10, 44 26, 40 52"/><path d="M30 42 L 40 54 L 50 42"/></svg></div>
            <div class="xv-cwin">
                <div class="xv-cwin__bar">
                    <span class="xv-cwin__name"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                    <span class="xv-cwin__date">Wed 30 Sept · 13:10 · Sample day</span>
                </div>
                <div class="xv-cwin__body" aria-live="polite">
                    <div class="xv-cview xv-cview--home" data-on="1">
                        <h3 class="xv-cq-title">What’s on your mind?</h3>
                        <div class="xv-starters">
                            <button type="button" class="xv-starter xv-starter--travel" data-q="0"><span class="xv-st-tag"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="5" r="2"/><circle cx="18" cy="19" r="2"/><path d="M6 7v4a4 4 0 0 0 4 4h4a4 4 0 0 1 4 4"/></svg>Travel</span><strong>When should I leave for my 14:00?</strong><small>Neumarkt → Mülheim · tram 4</small></button>
                            <button type="button" class="xv-starter xv-starter--paper" data-q="1"><span class="xv-st-tag"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $file !!}</svg>Paperwork</span><strong>What do I need to bring at 14:00?</strong><small>Your EU Blue Card checklist</small></button>
                            <button type="button" class="xv-starter xv-starter--events" data-q="2"><span class="xv-st-tag"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M3.5 10h17M8 3v4M16 3v4"/></svg>Events</span><span class="xv-st-stamp xv-marker">30 Sept</span><strong>What’s on tonight?</strong><small>2 sample events from 18:30</small></button>
                            <button type="button" class="xv-starter xv-starter--near" data-q="3"><img src="{{ asset('marketing/landing/map-0.webp') }}" alt="" width="240" height="135" loading="lazy"><span class="xv-st-tag"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $pin !!}</svg>Nearby</span><strong>Is there a pharmacy near my appointment?</strong><small>Around Wiener Platz, Mülheim</small></button>
                        </div>
                        <div class="xv-chips"><button type="button" class="xv-chip" data-q="4">Is the cinema step-free?</button></div>
                    </div>

                    <div class="xv-cview xv-ans" data-a="0" data-on="0">
                        <button type="button" class="xv-back">← All questions</button>
                        <p class="xv-you">When should I leave for my 14:00?</p>
                        <span class="xv-by"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                        <p class="xv-a-title">Leave now — it’s 13:10.</p>
                        <div class="xv-way">
                            <small class="xv-way__h">Your way</small>
                            <div class="xv-way__row"><b>13:10</b><i class="xv-way__d"></i><span><strong>Walk to Neumarkt</strong><small>4 min on foot</small></span></div>
                            <div class="xv-way__row xv-way__row--ride"><b>13:14</b><i class="xv-way__d xv-way__d--o"></i><span><strong><em class="xv-kvb">4</em>Tram 4 to Wiener Platz</strong><small>From Neumarkt · towards Schlebusch · get off at 13:42</small></span></div>
                            <div class="xv-way__row"><b>13:42</b><i class="xv-way__d xv-way__d--o"></i><span><strong>Walk to your appointment</strong><small>From Wiener Platz · 6 min on foot</small></span></div>
                            <div class="xv-way__row xv-way__row--end"><b>13:48</b><i class="xv-way__d xv-way__d--sq"></i><span><strong>Blue Card appointment</strong><small>Starts at 14:00 · Mülheim</small></span></div>
                        </div>
                        <p class="xv-a-p">You’ll be there at 13:48 with 12 minutes to spare. I skipped the 13:24 in your plan: it leaves only 2, and official appointments need 10.</p>
                        <div class="xv-a-actions"><span class="xv-a-btn">Open in Travel</span><small>Scheduled sample times, not live.</small></div>
                    </div>

                    <div class="xv-cview xv-ans" data-a="1" data-on="0">
                        <button type="button" class="xv-back">← All questions</button>
                        <p class="xv-you">What do I need to bring at 14:00?</p>
                        <span class="xv-by"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                        <p class="xv-a-title">1 of 5 documents marked ready.</p>
                        <div class="xv-docs">
                            <div class="xv-docs__head"><span>Your EU Blue Card checklist · your records</span><b>1 of 5 ready</b></div>
                            <span class="xv-docs__bar"><span></span></span>
                            <ul>
                                <li class="xv-docs__ok"><i></i><span>Valid passport and national D visa<small>Checked and ready</small></span></li>
                                <li class="xv-docs__half"><i></i><span>Employment contract or binding job offer covering at least six months<small>You have it · not checked yet</small></span></li>
                                <li><i></i><span>Qualification evidence and recognition evidence where required<small>Still to get</small></span></li>
                                <li><i></i><span>Health-insurance evidence<small>Still to get</small></span></li>
                                <li><i></i><span>Professional licence for a regulated profession, where applicable<small>Still to get · May be needed</small></span></li>
                            </ul>
                            <span class="xv-docs__src"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $link !!}</svg>Stadt Köln · EU Blue Card · Verification recorded 3 Aug 2026 · version 2026-08-03.1</span>
                        </div>
                        <p class="xv-a-p xv-a-p--small">Sample checklist, not legal advice. The items are the catalogue’s wording; the official sources are in Paperwork.</p>
                    </div>

                    <div class="xv-cview xv-ans" data-a="2" data-on="0">
                        <button type="button" class="xv-back">← All questions</button>
                        <p class="xv-you">What’s on tonight?</p>
                        <span class="xv-by"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                        <p class="xv-a-title">Two sample events tonight.</p>
                        <div class="xv-ev-cards">
                            <article class="xv-ev"><img src="{{ asset('marketing/landing/poster-street.webp') }}" alt="Sample poster: Street culture night, tonight 18:30 until 21:00, Rheinauhafen" width="586" height="808" loading="lazy"><span class="xv-ev__meta"><small>18:30–21:00 · Rheinauhafen</small><span class="xv-ev__add" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $calendarPlus !!}</svg></span></span></article>
                            <article class="xv-ev"><img src="{{ asset('marketing/landing/poster-cinema.webp') }}" alt="Sample poster: Cinema under the stars, tonight 20:00 until 22:00, Stadtgarten" width="582" height="806" loading="lazy"><span class="xv-ev__meta"><small>20:00–22:00 · Stadtgarten</small><span class="xv-ev__add" aria-hidden="true"><svg viewBox="0 0 24 24">{!! $calendarPlus !!}</svg></span></span></article>
                        </div>
                        <p class="xv-a-p xv-a-p--small">Sample programme. Adding one isn’t a booking; with “Ask first”, Composer asks before it changes your plan.</p>
                    </div>

                    <div class="xv-cview xv-ans" data-a="3" data-on="0">
                        <button type="button" class="xv-back">← All questions</button>
                        <p class="xv-you">Is there a pharmacy near my appointment?</p>
                        <span class="xv-by"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                        <p class="xv-a-title">Three near Wiener Platz.</p>
                        <p class="xv-a-p xv-a-p--small">Nearest first. Hours are the listed ones for 14:30, when your appointment ends, not a live check.</p>
                        <ul class="xv-near">
                            <li><img src="{{ asset('marketing/landing/map-0.webp') }}" alt="" width="240" height="135" loading="lazy"><span><small>Pharmacy · 50 m</small><strong>Eck-Apotheke</strong><small>Listed until 18:30 · Step-free access</small></span></li>
                            <li><img src="{{ asset('marketing/landing/map-1.webp') }}" alt="" width="240" height="135" loading="lazy"><span><small>Pharmacy · 150 m</small><strong>Einhorn-Apotheke</strong><small>Listed until 19:00 · Step-free access</small></span></li>
                            <li><img src="{{ asset('marketing/landing/map-2.webp') }}" alt="" width="240" height="135" loading="lazy"><span><small>Pharmacy · 200 m</small><strong>Brücken-Apotheke</strong><small>Listed until 19:00 · Step-free access</small></span></li>
                        </ul>
                        <div class="xv-a-actions"><span class="xv-a-btn">Show on map</span><small>From OpenStreetMap contributors (ODbL), sample export of 7 Oct 2026</small></div>
                    </div>

                    <div class="xv-cview xv-ans" data-a="4" data-on="0">
                        <button type="button" class="xv-back">← All questions</button>
                        <p class="xv-you">Is the cinema step-free?</p>
                        <span class="xv-by"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $spark !!}</svg>Composer</span>
                        <p class="xv-a-title">Not listed.</p>
                        <p class="xv-a-p">Neither Cinema under the stars nor Stadtgarten’s listing records step-free access, so I won’t guess. It’s worth asking the organiser before you go.</p>
                        <dl class="xv-facts">
                            <div><dt>Accessibility</dt><dd>Not listed</dd></div>
                            <div><dt>Admission</dt><dd>Not listed</dd></div>
                            <div><dt>Starts</dt><dd>20:00</dd></div>
                        </dl>
                    </div>
                </div>
                <div class="xv-cwin__input" aria-hidden="true"><span>Ask, discover, or plan…</span><span class="xv-cwin__row"><span>+</span><span>Ask first ▾</span><span class="xv-cwin__send">↑</span></span></div>
                <span class="xv-finger" aria-hidden="true"></span>
                <span class="xv-ghost" aria-hidden="true"></span>
            </div>
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
        <svg data-scrub="enter" data-span="1.1" viewBox="300 60 760 520" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Illustrative map of Cologne showing the sample day: from Neumarkt by tram 4 to the Blue Card appointment in Mülheim, then to Rheinauhafen in the evening">
            <path class="xv-map-streets" d="M-900 200 H2300 M-900 330 H2300 M-900 470 H2300 M-500 -200 V800 M-200 -200 V800 M180 -200 V800 M380 -200 V800 M560 -200 L 520 800 M960 -200 V800 M1080 -200 L 1120 800 M1400 -200 V800 M1700 -200 V800 M2000 -200 V800"/>
            <path d="M760 660 C 700 540, 600 490, 640 380 S 820 240, 770 140 S 830 30, 900 -40" stroke="#05badd" stroke-width="96" fill="none" stroke-linecap="round"/>
            <path d="M760 660 C 700 540, 600 490, 640 380 S 820 240, 770 140 S 830 30, 900 -40" stroke="#9eeefd" stroke-width="3" stroke-dasharray="3 14" fill="none"/>
            <text x="664" y="566" font-size="18" font-weight="700" fill="#005466" letter-spacing="6" transform="rotate(-62 664 566)">RHEIN</text>
            <g class="xv-map-label" font-size="22" font-weight="600" fill="#4b2a8f"><text x="316" y="276">Ehrenfeld</text><text x="360" y="180">Neustadt-Nord</text><text x="840" y="410">Deutz</text></g>
            <path fill="#2a0f5c" d="M560 330 V290 H566 V262 L569 259 L573 228 L577 259 L580 262 V278 L590 272 L600 278 V262 L603 259 L607 228 L611 259 L614 262 V290 H620 V330 Z"/>
            <text class="xv-map-dom" x="570" y="352" font-size="15" font-weight="700" fill="#2a0f5c">Dom</text>
            <path class="xv-map-route" d="M480 400 C 560 392, 620 380, 680 362 S 800 312, 830 250 S 880 176, 920 150" stroke="#a01e5a" stroke-width="12" fill="none" stroke-linecap="round"/>
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
                <h3>You decide what changes</h3>
                <p>Composer asks first, or makes only the small time and place edits you allow, each with Undo. Expadu never books, pays or submits anything for you.</p>
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
                    <a href="#street">Events</a>
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
            <span>Times, events, places and checklists on this page are sample content from Expadu’s preview; the street, posters and map are illustrations. Expadu isn’t legal advice. Place data © OpenStreetMap contributors.</span>
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
