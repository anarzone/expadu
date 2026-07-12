@extends('marketing.layout')

@section('canonical', route('home'))

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
                'description' => 'The AI companion for your new life in Germany — bureaucracy guides in English with official sources, situation-aware transit, and an AI day composer. Built for Cologne.',
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

@section('content')

{{-- ══ HERO ══ --}}
<header class="hero" id="top">
    <div class="wrap hero-grid">
        <div>
            <span class="eyebrow">Built for Cologne · in English</span>
            <h1>The AI companion for your new life in Germany.</h1>
            <p class="sub">Tell it your day — it plans the rest. The paperwork, the transit, the city: sorted for your exact situation.</p>
            <div class="hero-ctas">
                <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
                <a class="btn btn-ghost" href="#composer">See how it works</a>
            </div>
            <div class="hero-proof">
                <span>{{ $stats['guides'] }} official-source guides</span>
                <span>{{ $stats['places_label'] }} places</span>
                <span>{{ $stats['events_label'] }} local events</span>
                <span>Free during the Cologne launch</span>
            </div>
        </div>
        <div class="demo" aria-label="Product demo">
            <div class="demo-label"><b>✦</b> Ask or plan</div>
            <div class="demo-input"><span id="demoPrompt">{{ $demoScenarios[0]['prompt'] }}</span></div>
            <div class="demo-cards" id="demoCards">
                {{-- Scenario 1 server-rendered: readable without JavaScript; the demo loop takes over when it runs --}}
                @foreach ($demoScenarios[0]['cards'] as $card)
                    <div class="demo-card show">
                        <div class="band">{{ $card['band'] }}</div>
                        <div class="t">{{ $card['t'] }}</div>
                        <div class="m">{{ $card['m'] }}</div>
                        <div class="why">{{ $card['why'] }}</div>
                    </div>
                @endforeach
            </div>
            <div class="demo-note">Tell it your day. It plans the rest. <span style="opacity:.55">· demo</span></div>
        </div>
    </div>
</header>

<script type="application/json" id="demo-data">@json($demoScenarios)</script>

{{-- ══ PERSONAS ══ --}}
<section class="band-soft" id="paths">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Your situation is the starting point</span>
            <h2>Landing in Cologne as a…</h2>
            <p class="sub">Different situation, different paperwork, different deadlines. Expadu builds your path — not a generic checklist.</p>
        </div>
        <div class="persona-tabs" role="tablist" id="personaTabs">
            @foreach ($personas as $name => $persona)
                <button role="tab" data-persona="{{ $name }}" aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ $name }}</button>
            @endforeach
        </div>
        @foreach ($personas as $name => $persona)
            <div class="persona-card" data-persona-card="{{ $name }}" @unless ($loop->first) hidden @endunless>
                <div class="eyebrow" style="margin-bottom:6px">Your first three tasks</div>
                @foreach ($persona['tasks'] as $task)
                    <div class="ptask">
                        <span class="n">0{{ $loop->iteration }}</span>
                        <span class="t"><b>{{ $task['title'] }}</b><span>{{ $task['meta'] }}</span></span>
                        <span class="deadline{{ $loop->first ? '' : ' calm' }}">{{ $task['deadline'] }}</span>
                    </div>
                @endforeach
                <div class="persona-foot">{{ $persona['note'] }} <b style="color:var(--ink)">…and it keeps adapting — 20+ situations covered.</b></div>
            </div>
        @endforeach
    </div>
</section>

{{-- ══ BUREAUCRACY ══ --}}
<section id="bureaucracy">
    <div class="wrap split">
        <div>
            <span class="eyebrow">The part everyone dreads</span>
            <h2>German bureaucracy, decoded — in English, with sources.</h2>
            <p class="sub">Exact documents, real fees, actual deadlines. Every claim links to the official page, dated when we last checked it.</p>
            <ul class="feature-list">
                <li><b>{{ $stats['guides'] }} step-by-step guides</b> — from Anmeldung to permanent residency</li>
                <li><b>Document checklists</b> you tick off, task by task</li>
                <li><b>Appointment tracking</b> — with a leave-by time on the day</li>
                <li><b>Life events</b> — new baby, graduation, new job? Your path updates itself</li>
            </ul>
            <div class="mock-task" aria-label="Example task card">
                <div class="head"><b>Anmeldung — register your address</b><span class="verified">✓ source-checked</span></div>
                <div style="font-size:13px;color:var(--muted);margin:4px 0 8px">Within 14 days of moving in · free of charge</div>
                <div class="doc done"><i></i>Passport</div>
                <div class="doc done"><i></i>Wohnungsgeberbestätigung (landlord form)</div>
                <div class="doc"><i></i>Anmeldeformular — we link the exact PDF</div>
                <div class="src">Source: <a href="https://www.stadt-koeln.de" rel="noopener" target="_blank">stadt-koeln.de</a> · checked Jul 2026</div>
            </div>
            <div class="srcbadges"><span>stadt-koeln.de</span><span>BAMF</span><span>ELSTER</span><span>Ausländerbehörde Köln</span></div>
        </div>
        <div>
            <div class="phone"><img src="{{ asset('marketing/bureaucracy-mobile.jpg') }}" alt="Expadu bureaucracy checklist with settlement phases" loading="lazy" width="390" height="730"></div>
            <div class="phone-cap">your settlement path · phases from “before you fly” to “permanent”</div>
        </div>
    </div>
</section>

{{-- ══ TRANSIT ══ --}}
<section class="band-soft" id="transit">
    <div class="wrap split">
        <div>
            <div class="phone dark-frame"><img src="{{ asset('marketing/timetable-dark-mobile.jpg') }}" alt="Expadu live departures board in dark mode" loading="lazy" width="390" height="730"></div>
            <div class="phone-cap">live departures · the board knows where you are</div>
        </div>
        <div>
            <span class="eyebrow">Getting around</span>
            <h2>Transit that speaks newcomer.</h2>
            <p class="sub">Journeys, departures and disruptions on live KVB/VRS data — explained in English, priced for the ticket you actually hold.</p>
            <div class="tablebox" style="margin-top:22px">
                <table class="cmp">
                    <thead><tr><th scope="col">What newcomers need</th><th scope="col">Expadu</th><th scope="col">Typical transit apps</th></tr></thead>
                    <tbody>
                        <tr><td>Everything in English — including disruptions</td><td class="yes">✓</td><td class="meh">partly</td></tr>
                        <tr><td>Fares that know your ticket (Deutschlandticket, SemesterTicket — 2026 tariff)</td><td class="yes">✓</td><td class="meh">—</td></tr>
                        <tr><td>Plans around your appointments, with leave-by times</td><td class="yes">✓</td><td class="meh">—</td></tr>
                        <tr><td>Delays shown with a way around them</td><td class="yes">✓</td><td class="meh">partly</td></tr>
                        <tr><td>Suggestions that fit your situation</td><td class="yes">✓</td><td class="meh">—</td></tr>
                    </tbody>
                    <tfoot><tr><td colspan="3">Cologne + region · live KVB/VRS data</td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</section>

{{-- ══ COMPOSER ══ --}}
<section id="composer">
    <div class="wrap">
        <div class="sec-head" style="text-align:center;margin-inline:auto">
            <span class="eyebrow">The day composer</span>
            <h2>A local friend with a plan.</h2>
            <p class="sub" style="margin-inline:auto">Say what kind of day you want. Get a day with a shape — and reasons — not a list of pins.</p>
        </div>
        <div class="steps3">
            <div class="stepcard">
                <div class="k">1 · Say it</div>
                <h3>Plain words in, plain words out</h3>
                <div class="sentence">“<u>Saturday afternoon</u> around <u>Ehrenfeld</u>, for <u>family time</u> — from <u>Home</u>.”</div>
                <p>It reads your sentence — and every word stays editable. Tap any part to change it.</p>
            </div>
            <div class="stepcard">
                <div class="k">2 · Get a shaped day</div>
                <h3>Time bands + a why for every pick</h3>
                <div class="mini-slot">
                    <div class="band">Afternoon</div>
                    <div class="t">Blücherpark playground</div>
                    <div class="why">sunny window until 17:00</div>
                </div>
                <div class="connector">🚶 6 min · same area</div>
                <div class="mini-slot">
                    <div class="band">Late afternoon</div>
                    <div class="t">Gelato on Körnerstraße</div>
                    <div class="why">open till 19:00 · kid-approved</div>
                </div>
            </div>
            <div class="stepcard">
                <div class="k">3 · Make it yours</div>
                <h3>Lock it, swap it, go</h3>
                <div class="mini-slot">
                    <div class="band">Evening</div>
                    <div class="t">Herbrand’s beer garden <span class="slot-ops"><span class="hot">📌 lock</span><span>↻ swap</span><span>✕</span></span></div>
                    <div class="why">leave by 21:40 for the last direct tram</div>
                </div>
                <p style="margin-top:2px">Every stop has a <span class="takeme">take me there →</span> with live legs, platform and fare.</p>
            </div>
        </div>
        <div class="honesty">
            <b>AI plans your day — it never invents the facts.</b><br>
            <span style="color:var(--muted);font-size:14px">Opening hours, fares and deadlines come from verified data. Always.</span>
        </div>
    </div>
</section>

{{-- ══ CONTEXT STRIP ══ --}}
<section class="band-soft" id="context">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Proactive, not another map</span>
            <h2>It watches the boring stuff, so you don’t have to.</h2>
        </div>
        <div class="ctx-grid">
            <div class="ctx">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></div>
                <b>Anmeldung due in 6 days</b>
                <span>booked yet? Here’s the office and what to bring.</span>
            </div>
            <div class="ctx">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15a4 4 0 0 1 2-7.5A5 5 0 0 1 16 6a4.5 4.5 0 0 1 3 8"/><path d="M8 19l-1 2M13 19l-1 2M18 19l-1 2"/></svg></div>
                <b>Rain at 17:00</b>
                <span>your plan quietly swaps the park for somewhere indoors.</span>
            </div>
            <div class="ctx">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v9"/><path d="M12 17h.01"/><path d="M10.3 3.9 2.6 17a2 2 0 0 0 1.7 3h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></div>
                <b>Line 3 is disrupted</b>
                <span>you get the alert in English — with a way around it.</span>
            </div>
            <div class="ctx">
                <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/></svg></div>
                <b>Tomorrow’s a holiday</b>
                <span>shops close — better do the groceries tonight.</span>
            </div>
        </div>
    </div>
</section>

{{-- ══ TOOLS ══ --}}
<section id="tools">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Useful before you even sign up</span>
            <h2>Free tools — no account needed.</h2>
        </div>
        <div class="tool-grid">
            <div class="tool">
                <span class="free">free · no signup</span>
                <h3>Deutschlandticket break-even</h3>
                <p>Is the D-Ticket worth it for how you actually move? Two inputs, honest verdict, 2026 Cologne tariff.</p>
                <a href="{{ route('home') }}#tools">Coming this week →</a>
            </div>
            <div class="tool">
                <span class="free">free · no signup</span>
                <h3>Permanent-residency timeline</h3>
                <p>Your earliest Niederlassungserlaubnis date — and what legally speeds it up.</p>
                <a href="{{ route('home') }}#tools">Coming this week →</a>
            </div>
            <div class="tool">
                <span class="free">free · no signup</span>
                <h3>Citizenship quiz</h3>
                <p>Are you on track for a German passport? Eight questions against the current rules, with sources.</p>
                <a href="{{ route('home') }}#tools">Coming this week →</a>
            </div>
        </div>
    </div>
</section>

{{-- ══ TRUST ══ --}}
<section style="padding-top:0">
    <div class="wrap">
        <div class="trust">
            <div class="trust-grid">
                <div>
                    <span class="flap">@foreach (str_split((string) $stats['guides']) as $char)<b>{{ $char }}</b>@endforeach</span>
                    <div class="trust-label">official-source guides</div>
                </div>
                <div>
                    <span class="flap">@foreach (str_split(number_format($stats['places'])) as $char)<b>{{ $char }}</b>@endforeach</span>
                    <div class="trust-label">Cologne places</div>
                </div>
                <div>
                    <span class="flap">@foreach (str_split(number_format($stats['events'])) as $char)<b>{{ $char }}</b>@endforeach</span>
                    <div class="trust-label">local events tracked</div>
                </div>
            </div>
            <p class="trust-foot">Built in Cologne by an expat who did all of this the hard way — so you don’t have to.</p>
            <div class="trust-badges">
                <span>sources: stadt-koeln.de · BAMF · ELSTER</span>
                <span>live KVB/VRS data</span>
                <span>EU-hosted · Germany</span>
            </div>
        </div>
    </div>
</section>

{{-- ══ FAQ ══ --}}
<section class="band-soft" id="faq">
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Before you ask</span>
            <h2>Questions, answered.</h2>
        </div>
        <div class="faq">
            @foreach ($faqs as $faq)
                <details @if ($loop->first) open @endif>
                    <summary>{{ $faq['q'] }}</summary>
                    <div class="a">{{ $faq['a'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ══ FINALE ══ --}}
<section class="finale">
    <div class="wrap">
        <h2>Your first years in Germany, handled.</h2>
        <p class="sub" style="margin-inline:auto">Set up your situation once. Expadu takes it from there.</p>
        <div style="margin-top:26px"><a class="btn btn-primary" href="{{ route('register') }}">Start free</a></div>
    </div>
</section>

@endsection
