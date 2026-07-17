@extends('marketing.layout')

@section('title', 'Permanent residency (Niederlassungserlaubnis) timeline calculator — Expadu')
@section('meta_description', 'When can you get German permanent residency? Watch your timeline fill up — Blue Card, skilled worker, family or general track (§9/§18c/§28 AufenthG).')
@section('canonical', route('tools.residency'))
@section('waitlist_source', 'tool-residency')

@push('head')
    @vite('resources/js/marketing-tools.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'German permanent-residency timeline calculator',
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem' => 'Web',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'url' => route('tools.residency'),
    ], JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">Free tool · AufenthG tracks</span>
        <h1>When can you get permanent residency?</h1>
        <p class="sub">The Niederlassungserlaubnis ends visa renewals for good. Watch your timeline fill up.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-residency">
            <div class="tfield">
                <label>Your permit</label>
                <div class="chips-row" id="ne-track">
                    @foreach ($tracks as $key => $track)
                        <button type="button" data-v="{{ $key }}" data-months="{{ $track['months'] }}" aria-pressed="{{ $key === 'skilled_worker' ? 'true' : 'false' }}">{{ $track['label'] }}</button>
                    @endforeach
                </div>
                <small id="ne-trackNote"></small>
            </div>
            <div class="tfield">
                <label id="ne-since-label">Holding it since</label>
                {{-- Custom month picker — the native month input clashes with the
                     brand. The hidden input keeps the YYYY-MM contract. --}}
                <input type="hidden" id="ne-since" value="">
                <div class="mpick" role="group" aria-labelledby="ne-since-label">
                    <div class="mpick-year"><button type="button" id="ne-yearPrev" aria-label="Previous year">‹</button><b id="ne-yearVal">2024</b><button type="button" id="ne-yearNext" aria-label="Next year">›</button></div>
                    <div class="mpick-grid" id="ne-monthGrid"></div>
                </div>
            </div>
            <div class="tfield" id="ne-b1Field" hidden>
                <label class="check"><input type="checkbox" id="ne-b1" checked> I have B1 German</label>
                <small>Blue Card: 21 months with B1, 27 with A1.</small>
            </div>
            <div class="tfield" id="ne-degField" hidden>
                <label class="check"><input type="checkbox" id="ne-deg"> German university degree</label>
                <small>Skilled workers with a German degree: 2 years (§18c).</small>
            </div>
        </form>

        <div class="tool-result" id="ne-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>Date math is the easy part.</b> The Ausländerbehörde also wants pension contributions, secured livelihood, B1 German (track-dependent) and adequate housing — Expadu tracks those for you.</p>
            <p class="sources">Thresholds: §9 / §18c / §28 AufenthG, <a href="https://www.gesetze-im-internet.de/aufenthg_2004/__9.html" rel="noopener" target="_blank">gesetze-im-internet.de</a> · the same engine the Expadu app uses.</p>
        </div>

        <div class="tool-cta">
            <p>Expadu <b>counts this down for you</b> — and tells you the moment you cross the bar.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
