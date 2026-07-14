@extends('marketing.layout')

@section('title', 'Netto-Brutto calculator '.$year.' — what\'s left of your German salary? — Expadu')
@section('meta_description', 'Gross to net salary in Germany, '.$year.' rules: income tax (§32a), solidarity surcharge, church tax and all social insurance — with every constant sourced. Free, no signup.')
@section('canonical', route('tools.netto'))
@section('waitlist_source', 'tool-netto')

@push('head')
    @vite('resources/js/marketing-tools.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'German netto-brutto salary calculator '.$year,
        'applicationCategory' => 'FinanceApplication',
        'operatingSystem' => 'Web',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'url' => route('tools.netto'),
    ], JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">Free tool · {{ $year }} tax + insurance tables</span>
        <h1>What's actually left of your German salary?</h1>
        <p class="sub">Gross to net, with the {{ $year }} numbers: income tax, solidarity surcharge, church tax and all four social insurances — every constant sourced below.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-netto">
            <div class="tfield">
                <label for="nb-gross">Gross salary (€ per month)</label>
                <input type="number" id="nb-gross" min="0" max="100000" step="50" value="4000" inputmode="numeric">
                <small>Your contract salary before anything is deducted.</small>
            </div>
            <div class="tfield">
                <label for="nb-class">Tax class</label>
                <select id="nb-class">
                    <option value="1" selected>I — single (also II approx.)</option>
                    <option value="4">IV — married, both working</option>
                    <option value="3">III — married, main earner</option>
                </select>
                <small>Classes V/VI shift allowances between spouses — ask payroll; this tool covers the common cases.</small>
            </div>
            <div class="tfield">
                <label class="check"><input type="checkbox" id="nb-church"> I pay church tax</label>
                <small>9% of income tax (8% in Bavaria and Baden-Württemberg).</small>
            </div>
            <div class="tfield" id="nb-church-state" hidden>
                <label class="check"><input type="checkbox" id="nb-bybw"> …in Bavaria or Baden-Württemberg (8%)</label>
            </div>
            <div class="tfield">
                <label class="check"><input type="checkbox" id="nb-kids"> I have children</label>
                <small>Without children (age 23+) care insurance charges a 0.6% surcharge.</small>
            </div>
        </form>

        <div class="tool-result" id="nb-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>An honest estimate, not your payslip.</b> Payroll software applies the official day-exact algorithm (plus any personal allowances) — expect a difference of a few euros. Benefits in kind, company cars and 13th salaries aren't modelled here.</p>
            <p class="sources">Sources: <a href="{{ $sourceTariff }}" rel="noopener" target="_blank">§32a EStG (tariff)</a> · <a href="{{ $sourceSv }}" rel="noopener" target="_blank">Beitragsbemessungsgrenzen {{ $year }}</a> · average Zusatzbeitrag {{ number_format($toolData['social']['health_zusatz_avg'] * 100, 1) }}% (your Krankenkasse may differ) · verified {{ $verifiedAt }} · calculations happen in your browser.</p>
        </div>

        <div class="tool-cta">
            <p>Salary sorted? Expadu handles <b>everything else about arriving</b> — the permits, the deadlines, the city.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
