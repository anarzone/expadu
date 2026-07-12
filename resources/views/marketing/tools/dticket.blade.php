@extends('marketing.layout')

@section('title', 'Deutschlandticket break-even calculator (Cologne, 2026) — Expadu')
@section('meta_description', 'Is the €63 Deutschlandticket worth it for you? Compare against Cologne\'s 2026 per-ride fares (Rheinlandtarif) in ten seconds. Free, no signup.')
@section('canonical', route('tools.dticket'))
@section('waitlist_source', 'tool-dticket')

@push('head')
    @vite('resources/js/marketing-tools.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'Deutschlandticket break-even calculator',
        'applicationCategory' => 'FinanceApplication',
        'operatingSystem' => 'Web',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'url' => route('tools.dticket'),
    ], JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">Free tool · Cologne 2026 tariff</span>
        <h1>Is the Deutschlandticket worth it for you?</h1>
        <p class="sub">€{{ number_format($dticketEur, 0) }} a month, all local transit in Germany. Whether that beats paying per ride depends on one thing: how often you actually ride.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-dticket">
            <div class="tfield">
                <label for="dt-trips">Single trips per week</label>
                <input type="number" id="dt-trips" min="0" max="60" step="1" value="8" inputmode="numeric">
                <small>Count each direction — a commute is usually 10.</small>
            </div>
            <div class="tfield">
                <label for="dt-fare">Your typical trip</label>
                <select id="dt-fare">
                    <option value="K">Kurzstrecke — up to 4 stops (€{{ number_format($fares['K'], 2) }})</option>
                    <option value="1b" selected>Within Köln / Bonn / Aachen (€{{ number_format($fares['1b'], 2) }})</option>
                    <option value="1a">Within a smaller municipality (€{{ number_format($fares['1a'], 2) }})</option>
                    <option value="2">Into the surrounding region (€{{ number_format($fares['2'], 2) }})</option>
                    <option value="3">Across the whole Rheinland (€{{ number_format($fares['3'], 2) }})</option>
                </select>
            </div>
            <div class="tfield">
                <label for="dt-subsidy">Employer contribution (€/month)</label>
                <input type="number" id="dt-subsidy" min="0" max="63" step="1" value="0" inputmode="numeric">
                <small>Many employers pay part of a JobTicket (often down to ≈ €{{ number_format($jobticketEur, 0) }}).</small>
            </div>
        </form>

        <div class="tool-result" id="dt-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>Good to know:</b> with eezy.nrw check-in/check-out you never pay more than €{{ number_format($eezyCapEur, 0) }} a month anyway — the D-Ticket buys you simplicity and Germany-wide validity (local transit only, no IC/ICE).</p>
            <p class="sources">Fares: <a href="{{ $sourceUrl }}" rel="noopener" target="_blank">KVB price table</a> (Rheinlandtarif) · verified {{ $verifiedAt }} · calculations happen in your browser, nothing is sent anywhere.</p>
        </div>

        <div class="tool-cta">
            <p>Expadu shows a <b>covered-by-your-ticket</b> badge on every route it plans — and plans your whole day around it.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
