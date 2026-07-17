@extends('marketing.layout')

@section('title', 'Netto-Brutto calculator '.$year.' — what\'s left of your German salary? — Expadu')
@section('meta_description', 'Gross to net salary in Germany, '.$year.' rules: all six tax classes, every Bundesland, church tax, children, statutory or private insurance — cross-checked against the official BMF calculator. Free, no signup.')
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
        <span class="eyebrow">Free tool · full {{ $year }} payroll math</span>
        <h1>What's actually left of your German salary?</h1>
        <p class="sub">All six tax classes, every Bundesland, church tax, children, statutory or private insurance — the same formulas payroll uses, every constant sourced below.</p>

        <script type="application/json" id="tool-data">@json($toolData)</script>

        <form class="tool-form" id="tool-netto">
            <div class="tfield">
                <label for="nb-gross">Gross salary (€)</label>
                <div class="tf2">
                    <input type="number" id="nb-gross" min="0" max="1000000" step="50" value="4000" inputmode="numeric">
                    <div class="chips-row" id="nb-period">
                        <button type="button" data-v="m" aria-pressed="true">per month</button>
                        <button type="button" data-v="y" aria-pressed="false">per year</button>
                    </div>
                </div>
                <div class="slider-row" style="margin-top:12px"><input type="range" id="nb-grossR" min="1000" max="15000" step="50" value="4000" aria-label="Gross salary slider"></div>
            </div>

            <div class="tfield">
                <label>Tax class</label>
                <div class="chips-row" id="nb-class">
                    <button type="button" data-v="1" aria-pressed="true">I</button>
                    <button type="button" data-v="2" aria-pressed="false">II</button>
                    <button type="button" data-v="3" aria-pressed="false">III</button>
                    <button type="button" data-v="4" aria-pressed="false">IV</button>
                    <button type="button" data-v="5" aria-pressed="false">V</button>
                    <button type="button" data-v="6" aria-pressed="false">VI</button>
                </div>
                <small id="nb-classHint"></small>
            </div>

            <div class="tf2">
                <div class="tfield">
                    <label for="nb-land">Federal state</label>
                    <select id="nb-land">
                        <option value="BW">Baden-Württemberg</option>
                        <option value="BY">Bavaria (Bayern)</option>
                        <option value="BE">Berlin</option>
                        <option value="BB">Brandenburg</option>
                        <option value="HB">Bremen</option>
                        <option value="HH">Hamburg</option>
                        <option value="HE">Hesse (Hessen)</option>
                        <option value="MV">Mecklenburg-Vorpommern</option>
                        <option value="NI">Lower Saxony (Niedersachsen)</option>
                        <option value="NW" selected>North Rhine-Westphalia</option>
                        <option value="RP">Rhineland-Palatinate</option>
                        <option value="SL">Saarland</option>
                        <option value="SN">Saxony (Sachsen)</option>
                        <option value="ST">Saxony-Anhalt</option>
                        <option value="SH">Schleswig-Holstein</option>
                        <option value="TH">Thuringia (Thüringen)</option>
                    </select>
                    <small>Sets the church-tax rate (8% in BY/BW) and Saxony's care-insurance split.</small>
                </div>
                <div class="tfield">
                    <label>Membership &amp; family</label>
                    <label class="check"><input type="checkbox" id="nb-church"> Church member</label>
                    <label class="check" style="margin-top:8px"><input type="checkbox" id="nb-kids"> I have children</label>
                    <small>Without children (age 23+), care insurance adds 0.6%.</small>
                </div>
            </div>

            <div class="tf2" id="nb-kidsFields" hidden>
                <div class="tfield">
                    <label for="nb-kfb">Child allowance counters</label>
                    <select id="nb-kfb">
                        <option value="0.5">0.5</option><option value="1" selected>1.0</option><option value="1.5">1.5</option>
                        <option value="2">2.0</option><option value="2.5">2.5</option><option value="3">3.0</option>
                        <option value="4">4.0</option><option value="5">5.0</option><option value="6">6.0</option>
                    </select>
                    <small>Kinderfreibeträge on your tax record — lower church tax + Soli (classes I–IV).</small>
                </div>
                <div class="tfield">
                    <label for="nb-kidsU25">Children under 25</label>
                    <input type="number" id="nb-kidsU25" min="0" max="10" step="1" value="1">
                    <small>From the 2nd child under 25, care insurance drops 0.25% each (up to the 5th).</small>
                </div>
            </div>

            <div class="tfield">
                <label>Health insurance</label>
                <div class="chips-row" id="nb-kvType">
                    <button type="button" data-v="g" aria-pressed="true">Statutory (GKV)</button>
                    <button type="button" data-v="p" aria-pressed="false">Private (PKV)</button>
                </div>
            </div>
            <div class="tf2" id="nb-gkvFields">
                <div class="tfield">
                    <label for="nb-zusatz">Your fund's Zusatzbeitrag (%)</label>
                    <input type="number" id="nb-zusatz" min="0" max="5" step="0.1" value="{{ number_format($toolData['social']['health_zusatz_avg'] * 100, 1) }}">
                    <small>{{ $year }} average: {{ number_format($toolData['social']['health_zusatz_avg'] * 100, 1) }}%. Check your Krankenkasse — it varies 1–4%.</small>
                </div>
            </div>
            <div class="tf2" id="nb-pkvFields" hidden>
                <div class="tfield">
                    <label for="nb-prem">Monthly premium (€, incl. care)</label>
                    <input type="number" id="nb-prem" min="0" step="10" value="450">
                </div>
                <div class="tfield">
                    <label>Employer subsidy</label>
                    <label class="check"><input type="checkbox" id="nb-subs" checked> Employer pays half (capped)</label>
                    <small>{{ $year }} cap: about €613/month at the statutory maximum.</small>
                </div>
            </div>

            <details class="more">
                <summary>More options</summary>
                <div class="inner">
                    <div class="tf2">
                        <div class="tfield">
                            <label for="nb-benefit">Non-cash benefit (€/month)</label>
                            <input type="number" id="nb-benefit" min="0" step="10" value="0">
                            <small>Company car, job ticket above the limit — taxed but not paid out.</small>
                        </div>
                        <div class="tfield">
                            <label for="nb-freib">Registered tax allowance (€/year)</label>
                            <input type="number" id="nb-freib" min="0" step="100" value="0">
                            <small>A Freibetrag from the Lohnsteuer-Ermäßigung procedure, if you have one.</small>
                        </div>
                    </div>
                    <div class="tf2">
                        <div class="tfield"><label class="check"><input type="checkbox" id="nb-rvOn" checked> Statutory pension insurance</label></div>
                        <div class="tfield"><label class="check"><input type="checkbox" id="nb-avOn" checked> Unemployment insurance</label></div>
                    </div>
                </div>
            </details>
        </form>

        <div class="tool-result" id="nb-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>An honest estimate, not your payslip.</b> Assumes you're under 64 and no IV-with-factor; class II uses the one-child base relief; for private insurance the full premium is treated as basic cover. Payroll's day-exact algorithm may differ by a few euros.</p>
            <p class="sources">Sources, verified {{ $verifiedAt }}: <a href="{{ $sourceTariff }}" rel="noopener" target="_blank">§32a EStG (tariff)</a> · <a href="{{ $source39b }}" rel="noopener" target="_blank">§39b EStG</a> (classes V/VI, allowances) · <a href="https://www.gesetze-im-internet.de/estg/__32.html" rel="noopener" target="_blank">§32(6)</a> + <a href="https://www.gesetze-im-internet.de/estg/__24b.html" rel="noopener" target="_blank">§24b EStG</a> (children, single parents) · <a href="{{ $sourceCare }}" rel="noopener" target="_blank">§55/§58 SGB XI</a> (care insurance) · <a href="{{ $sourceSv }}" rel="noopener" target="_blank">Beitragsbemessungsgrenzen {{ $year }}</a> · wage tax cross-checked to the cent against the official <a href="https://www.bmf-steuerrechner.de" rel="noopener" target="_blank">BMF calculator</a> · calculations happen in your browser.</p>
        </div>

        <div class="tool-cta">
            <p>Salary sorted? Expadu handles <b>everything else about arriving</b> — the permits, the deadlines, the city.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
