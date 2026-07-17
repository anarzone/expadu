@extends('marketing.layout')

@section('title', 'Deutschlandticket break-even calculator (Cologne, 2026) — Expadu')
@section('meta_description', 'Is the €'.number_format($dticketEur, 0).' Deutschlandticket worth it for how you actually move? Drag your weekly trips — honest verdict with Cologne\'s current Rheinlandtarif fares. Free, no signup.')
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
        <p class="sub">€{{ number_format($dticketEur, 0) }}/month, all local transit in Germany. Drag how often you actually ride.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-dticket">
            <div class="tfield">
                <label for="dt-trips">Single trips per week <small style="display:inline;color:var(--muted);font-weight:400">(a commute is usually 10)</small></label>
                <div class="slider-row"><input type="range" id="dt-trips" min="0" max="30" step="1" value="8"><output id="dt-tripsOut">8 trips</output></div>
            </div>
            <div class="tfield">
                <label>Your typical trip</label>
                <div class="chips-row" id="dt-fare">
                    <button type="button" data-v="K" aria-pressed="false">Kurzstrecke €{{ number_format($fares['K'], 2) }}</button>
                    <button type="button" data-v="1b" aria-pressed="true">Within Köln €{{ number_format($fares['1b'], 2) }}</button>
                    <button type="button" data-v="2" aria-pressed="false">Region €{{ number_format($fares['2'], 2) }}</button>
                </div>
            </div>
            <div class="tfield">
                <label for="dt-subsidy">Employer contribution (€/month)</label>
                <div class="slider-row"><input type="range" id="dt-subsidy" min="0" max="{{ (int) $dticketEur }}" step="1" value="0"><output id="dt-subOut">€0</output></div>
            </div>
        </form>

        <div class="tool-result" id="dt-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>Good to know:</b> with eezy.nrw you never pay more than €{{ number_format($eezyCapEur, 0) }}/month for pay-as-you-go anyway — the D-Ticket buys simplicity and Germany-wide validity (no IC/ICE). Many employers subsidise it down to roughly €{{ number_format($jobticketEur, 0) }} as a Jobticket.</p>
            <p class="sources">Fares: <a href="{{ $sourceUrl }}" rel="noopener" target="_blank">{{ $sourceUrl }}</a> · verified {{ $verifiedAt }} · calculations happen in your browser.</p>
        </div>

        <div class="tool-cta">
            <p>Expadu shows a <b>covered-by-your-ticket</b> badge on every route it plans for you.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
