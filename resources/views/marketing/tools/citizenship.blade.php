@extends('marketing.layout')

@section('title', 'German citizenship quiz — are you on track? (2024 rules) — Expadu')
@section('meta_description', 'Five questions against the reformed StAG: 5-year standard track, 3-year fast track, spouse route — and dual citizenship. With sources, no signup.')
@section('canonical', route('tools.citizenship'))
@section('waitlist_source', 'tool-citizenship')

@push('head')
    @vite('resources/js/marketing-tools.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'German citizenship eligibility quiz',
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem' => 'Web',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'url' => route('tools.citizenship'),
    ], JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">Free tool · StAG, 2024 reform</span>
        <h1>Are you on track for a German passport?</h1>
        <p class="sub">Since June 2024: five years instead of eight, three with exceptional integration — and you keep your original citizenship.</p>

        <script type="application/json" id="tool-data">@json($rules)</script>

        <form class="tool-form" id="tool-citizenship">
            <div class="tfield">
                <label for="cz-years">Years living legally in Germany</label>
                <input type="number" id="cz-years" min="0" max="40" step="0.5" value="2" inputmode="decimal">
            </div>
            <div class="tfield">
                <label class="check"><input type="checkbox" id="cz-married"> Married to a German citizen for 2+ years</label>
                <small>Spouses: 3 years of residence + 2 years of marriage (§9 StAG).</small>
            </div>
            <div class="tfield">
                <label for="cz-german">Your German level</label>
                <select id="cz-german">
                    <option value="none">Below B1 (yet)</option>
                    <option value="b1" selected>B1 certified</option>
                    <option value="c1">C1 certified</option>
                </select>
            </div>
            <div class="tfield">
                <label class="check"><input type="checkbox" id="cz-livelihood" checked> I support myself without basic benefits</label>
                <small>Secured livelihood is a hard requirement on every track.</small>
            </div>
            <div class="tfield">
                <label class="check"><input type="checkbox" id="cz-test"> Naturalisation test passed</label>
                <small>33 questions, 17 to pass — bookable at the VHS.</small>
            </div>
        </form>

        <div class="tool-result" id="cz-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>This is a guide, not legal advice.</b> The fast 3-year track additionally requires special integration achievements (outstanding work, academic or civic engagement) that only the Einbürgerungsbehörde can assess. Criminal convictions and some benefit situations block naturalisation.</p>
            <p class="sources">
                Sources:
                @foreach ($rules['sources'] as $source)
                    <a href="{{ $source['url'] }}" rel="noopener" target="_blank">{{ $source['label'] }}</a>@if (! $loop->last) · @endif
                @endforeach
                · calculations happen in your browser.
            </p>
        </div>

        <div class="tool-cta">
            <p>Expadu tracks the whole road there — permits, deadlines, and the paperwork for each step, in English.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
