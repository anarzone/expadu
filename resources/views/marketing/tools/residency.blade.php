@extends('marketing.layout')

@section('title', 'Permanent residency (Niederlassungserlaubnis) timeline calculator — Expadu')
@section('meta_description', 'When can you get German permanent residency? Your earliest Niederlassungserlaubnis date on your track — Blue Card, skilled worker, family or general (§9 AufenthG).')
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
        <p class="sub">The Niederlassungserlaubnis ends visa renewals for good. Your earliest application date depends on which track you're on.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-residency">
            <div class="tfield">
                <label for="ne-track">Your permit</label>
                <select id="ne-track">
                    @foreach ($tracks as $key => $track)
                        <option value="{{ $key }}" @if ($key === 'skilled_worker') selected @endif>{{ $track['label'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="tfield">
                <label for="ne-since">Holding it since</label>
                <input type="month" id="ne-since" max="{{ now()->format('Y-m') }}">
            </div>
            <div class="tfield" id="ne-b1-field" hidden>
                <label class="check"><input type="checkbox" id="ne-b1" checked> I have B1 German</label>
                <small>Blue Card: 21 months with B1, 27 months with A1.</small>
            </div>
            <div class="tfield" id="ne-degree-field" hidden>
                <label class="check"><input type="checkbox" id="ne-degree"> My degree is from a German university</label>
                <small>Skilled workers with a German degree qualify after 2 years (§18c).</small>
            </div>
        </form>

        <div class="tool-result" id="ne-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>Date math is the easy part.</b> The Ausländerbehörde will also want: pension contributions for the qualifying period, a secured livelihood, B1 German (track-dependent), and adequate housing. This tool tells you when — the checklist in the app tells you what.</p>
            <p class="sources">Thresholds: §9 / §18c / §28 AufenthG, <a href="https://www.gesetze-im-internet.de/aufenthg_2004/__9.html" rel="noopener" target="_blank">gesetze-im-internet.de</a> · same engine as the Expadu app · calculations happen in your browser.</p>
        </div>

        <div class="tool-cta">
            <p>Expadu <b>counts this down for you</b> — and tells you the moment you cross the bar, with the documents to bring.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
