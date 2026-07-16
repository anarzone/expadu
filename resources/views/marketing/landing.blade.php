@extends('marketing.layout')

@section('title', 'Expadu — Tell it your day. It plans the rest.')
@section('meta_description', 'Expadu turns your situation, deadlines, ticket, weather and location into one useful plan for life in Germany. Built for Cologne, in English.')
@section('canonical', route('home'))
@section('body_class', 'landing-v2')

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
<header class="hero" id="top">
    <div class="wrap">
        <span class="eyebrow">Cologne first · built in English</span>
        <h1>Tell Expadu your day. It plans the rest.</h1>
        <p class="sub">Your situation, deadlines, ticket, weather and location become one useful next step — not another generic checklist.</p>
        <div class="hero-ctas">
            @auth
                <a class="btn btn-primary" href="{{ route('dashboard') }}">Open the app →</a>
            @else
                <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
            @endauth
            <a class="btn btn-ghost" href="#demo">Play with the demo ↓</a>
        </div>
        <div class="hero-proof">
            <span>{{ $stats['guides'] }} official-source guides</span>
            <span>{{ $stats['places_label'] }} places</span>
            <span>{{ $stats['events_label'] }} local events</span>
            <span>Free during the Cologne launch</span>
        </div>
    </div>
</header>

<div class="demo-stage" id="demo">
    <div class="wrap">
        <div class="demo" aria-label="Interactive Expadu plan demo">
            <div class="demo-top">
                <span class="demo-label"><b>✦</b> Ask or plan — live demo</span>
                <div class="wx" role="group" aria-label="Demo weather">
                    <button id="wxSun" type="button" aria-pressed="true">☀︎ clear</button>
                    <button id="wxRain" type="button" aria-pressed="false">🌧 rain later</button>
                </div>
            </div>
            <div class="personas" id="personaRow">
                <span>I'm a…</span>
                @foreach ($personas as $name => $persona)
                    <button type="button" data-persona="{{ $name }}" aria-pressed="{{ $name === 'Employee' ? 'true' : 'false' }}">{{ $name }}</button>
                @endforeach
            </div>
            <div class="chips" id="chipRow">
                @foreach ($personas['Employee']['chips'] as $scenarioKey)
                    <button type="button" data-scenario="{{ $scenarioKey }}">“{{ $demoScenarios[$scenarioKey]['label'] }}”</button>
                @endforeach
            </div>
            <div class="demo-input"><span id="dPrompt">{{ $demoScenarios['arrived']['label'] }}</span></div>
            <div class="plan" id="plan">
                @foreach ($demoScenarios['arrived']['cards'] as $card)
                    @unless ($loop->first)
                        <div class="conn in">🚶 {{ [6, 4][$loop->index - 1] ?? 5 }} min · chained for you</div>
                    @endunless
                    <article class="pcard in">
                        <div class="band">{{ $card['band'] }}</div>
                        <div class="t">
                            <span>{{ $card['title'] }}</span>
                            <span class="ops" aria-label="Edit this recommendation">
                                <button class="lock" type="button" title="Lock recommendation">📌</button>
                                <button class="swap" type="button" title="Swap recommendation">↻</button>
                                <button class="remove" type="button" title="Remove recommendation">✕</button>
                            </span>
                        </div>
                        <div class="m">{{ $card['meta'] }}</div>
                        <div class="why">{{ $card['why'] }}</div>
                    </article>
                @endforeach
            </div>
            <div class="demo-note">Demo data. The real app plans from {{ $stats['places_label'] }} Cologne places — and never invents an opening hour, fare or deadline.</div>
        </div>
    </div>
</div>

<script type="application/json" id="marketing-demo-data">{!! json_encode([
    'scenarios' => $demoScenarios,
    'personas' => $personas,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

<section class="block band-soft" id="bureaucracy">
    <div class="wrap split">
        <div class="reveal">
            <span class="eyebrow">The part everyone dreads</span>
            <h2 class="section-heading">German bureaucracy, decoded — in English, with sources.</h2>
            <p class="section-sub">Your situation builds the checklist. Every fee, deadline and document links to the official page.</p>
            <ul class="mini-feats">
                <li><b>{{ $stats['guides'] }} guides</b> — Anmeldung to permanent residency</li>
                <li><b>Deadlines tracked</b> — with a leave-by time on the day</li>
                <li><b>Life events</b> — new baby, new job? The path updates itself</li>
            </ul>
        </div>
        <div class="reveal">
            <div class="check-app" id="checkApp">
                <span class="try-hint">↓ try it — tick the documents</span>
                <div class="phases" aria-label="Settlement phases">
                    <div class="phase done"><i></i><b>Before fly</b></div>
                    <div class="phase now"><i></i><b>First 14 days</b></div>
                    <div class="phase"><i></i><b>First 90</b></div>
                    <div class="phase"><i></i><b>Settled</b></div>
                    <div class="phase"><i></i><b>Permanent</b></div>
                </div>
                <div class="task-head">
                    <b id="taskTitle">{{ $personas['Employee']['task']['title'] }}</b>
                    <span class="pill" id="taskDl">{{ $personas['Employee']['task']['deadline'] }}</span>
                </div>
                <div class="task-meta" id="taskMeta">{{ $personas['Employee']['task']['meta'] }}</div>
                <div id="docList">
                    @foreach ($personas['Employee']['task']['documents'] as $document)
                        <div class="doc" role="checkbox" tabindex="0" aria-checked="false"><i></i>{{ $document }}</div>
                    @endforeach
                </div>
                <div class="prog" aria-hidden="true"><i id="progBar"></i></div>
                <div class="check-src">
                    <span>Source: <a href="https://www.stadt-koeln.de/service/produkt/anmeldung-einer-wohnung" target="_blank" rel="noopener">stadt-koeln.de</a> · checked Jul 2026</span>
                    <span class="ok">✓ source-checked</span>
                </div>
                <div class="next-teaser" id="nextTeaser"></div>
            </div>
        </div>
    </div>
</section>

<section class="band-dark" id="transit">
    <div class="wrap">
        <span class="eyebrow">Getting around</span>
        <h2 class="section-heading">Transit that speaks newcomer.</h2>
        <p class="sub">Live KVB/VRS data, explained in English. Tap the delayed line in this demo.</p>
        <div class="board reveal" id="board">
            <div class="board-head"><span>KVB · Bf Ehrenfeld</span><span class="live">Demo · live format</span></div>
        </div>
        <div class="board-claims reveal">
            <span>everything in English — including disruptions</span>
            <span>fares that know your Deutschlandticket</span>
            <span>leave-by times on every plan</span>
        </div>
        <details class="reveal">
            <summary>How that compares to typical transit apps ▾</summary>
            <table class="cmp">
                <thead><tr><th scope="col">What newcomers need</th><th scope="col">Expadu</th><th scope="col">Typical apps</th></tr></thead>
                <tbody>
                    <tr><td>Everything in English — including disruptions</td><td class="yes">✓</td><td class="meh">partly</td></tr>
                    <tr><td>Fares that know your ticket (D-Ticket, SemesterTicket)</td><td class="yes">✓</td><td class="meh">—</td></tr>
                    <tr><td>Plans around appointments, with leave-by times</td><td class="yes">✓</td><td class="meh">—</td></tr>
                    <tr><td>Delays shown with a way around them</td><td class="yes">✓</td><td class="meh">partly</td></tr>
                    <tr><td>Suggestions that fit your situation</td><td class="yes">✓</td><td class="meh">—</td></tr>
                </tbody>
            </table>
        </details>
    </div>
</section>

<section class="block numbers">
    <div class="wrap reveal">
        <div class="num-grid">
            <div><span class="flapnum" data-number="{{ $stats['guides'] }}">@foreach (str_split((string) $stats['guides']) as $character)<b>{{ $character }}</b>@endforeach</span><div class="num-label">official-source guides</div></div>
            <div><span class="flapnum" data-number="{{ $stats['places'] }}">@foreach (str_split((string) $stats['places']) as $character)<b>{{ $character }}</b>@endforeach</span><div class="num-label">Cologne places</div></div>
            <div><span class="flapnum" data-number="{{ $stats['events'] }}">@foreach (str_split((string) $stats['events']) as $character)<b>{{ $character }}</b>@endforeach</span><div class="num-label">local events tracked</div></div>
        </div>
        <div class="founder-story">
            <div class="founder-copy">
                <span class="eyebrow">Why Expadu exists</span>
                <h2>Built in Cologne by an expat who had to figure it out first.</h2>
                <p>Expadu started with the scattered official pages, missed context and German-only notices that make a new country feel harder than it is. We turn them into one plan — and show where every fact came from.</p>
            </div>
            <div class="trust-list">
                <div class="trust-item"><b>AI plans the shape. Verified data supplies the facts.</b><span>No invented fees, opening hours or deadlines.</span></div>
                <div class="trust-item"><b>Official source and checked date shown</b><span>You can inspect the evidence behind important claims.</span></div>
                <div class="trust-sources">stadt-koeln.de · BAMF · ELSTER<br>live KVB/VRS data · EU-hosted</div>
            </div>
        </div>
    </div>
</section>

<section class="block band-soft" id="tools">
    <div class="wrap">
        <div class="head-sm reveal">
            <span class="eyebrow">Useful before you even sign up</span>
            <h2>Free tools — no account needed.</h2>
        </div>
        <div class="tool-grid reveal">
            <a class="tool" href="{{ route('tools.dticket') }}">
                <div class="glyph"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><rect x="4" y="3" width="16" height="15" rx="3"/><path d="M8 21h8M12 18v3M8 7h8M8 11h5"/></svg></div>
                <h3>Deutschlandticket break-even</h3>
                <p>Is €63/month worth it for how you actually move? Honest verdict, 2026 tariff.</p>
                <span class="go">Check yours →</span> <span class="free">· free</span>
            </a>
            <a class="tool" href="{{ route('tools.residency') }}">
                <div class="glyph"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg></div>
                <h3>Permanent-residency timeline</h3>
                <p>Your earliest Niederlassungserlaubnis date — and what can legally speed it up.</p>
                <span class="go">See your date →</span> <span class="free">· free</span>
            </a>
            <a class="tool" href="{{ route('tools.citizenship') }}">
                <div class="glyph"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 15V8l8-4 8 4v7"/><path d="M8 21v-6a4 4 0 0 1 8 0v6"/></svg></div>
                <h3>Citizenship quiz</h3>
                <p>Are you on track for a German passport under the current rules? Five questions, sourced.</p>
                <span class="go">Take the quiz →</span> <span class="free">· free</span>
            </a>
            <a class="tool" href="{{ route('tools.netto') }}">
                <div class="glyph"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 3v18M7 7h7a3 3 0 0 1 0 6H8a3 3 0 0 0 0 6h9"/></svg></div>
                <h3>Netto-brutto calculator</h3>
                <p>All six tax classes, church, children, private or statutory — see what actually lands in your account.</p>
                <span class="go">Calculate your net →</span> <span class="free">· free</span>
            </a>
        </div>
        <p class="tools-all reveal"><a href="{{ route('tools.index') }}">Every tool also lives on its own page →</a></p>
    </div>
</section>

<section class="block" id="guides">
    <div class="wrap">
        <div class="guide-head reveal">
            <div class="head-sm">
                <span class="eyebrow">The Expadu field guide</span>
                <h2>Practical English guides, checked against official sources.</h2>
                <p class="sub">Start with the two sequences that remove the most uncertainty from your first months.</p>
            </div>
            <a href="{{ route('blog.index') }}">Explore every guide →</a>
        </div>
        <div class="guide-grid reveal">
            <a class="guide-card" href="{{ route('blog.show', 'anmeldung-in-cologne-english-guide') }}">
                <div class="guide-copy">
                    <span class="guide-meta">Paperwork · 8 min read</span>
                    <h3>Anmeldung in Cologne, in English.</h3>
                    <p>The 14-day rule, exact documents, landlord form and what happens after the appointment.</p>
                    <span class="guide-link">Read the complete guide →</span>
                </div>
                <div class="guide-art" aria-hidden="true"><div class="guide-mark">Source<br>checked<br>Jul 2026</div></div>
            </a>
            <a class="guide-card" href="{{ route('blog.show', 'first-90-days-in-cologne-in-order') }}">
                <div class="guide-copy">
                    <span class="guide-meta">Start here · 10 min read</span>
                    <h3>Your first 90 days in Cologne, in order.</h3>
                    <p>What depends on what — from an address and insurance to banking, tax and residence tasks.</p>
                    <span class="guide-link">Follow the sequence →</span>
                </div>
                <div class="guide-art" aria-hidden="true"><div class="guide-mark">01→90</div></div>
            </a>
        </div>
    </div>
</section>

<section class="block" id="faq">
    <div class="wrap">
        <div class="head-sm reveal"><h2>Questions, answered.</h2></div>
        <div class="faq reveal">
            @foreach ($faqs as $faq)
                <details @if ($loop->first) open @endif>
                    <summary>{{ $faq['q'] }}</summary>
                    <div class="a">{{ $faq['a'] }}</div>
                </details>
            @endforeach
        </div>
    </div>
</section>

<section class="finale">
    <div class="wrap reveal">
        <h2>Your first years in Germany, handled.</h2>
        <p class="sub">Set up your situation once. Expadu takes it from there.</p>
        <div class="hero-ctas">
            @auth
                <a class="btn btn-primary" href="{{ route('dashboard') }}">Open the app →</a>
            @else
                <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
            @endauth
        </div>
    </div>
</section>
@endsection
