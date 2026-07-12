@extends('marketing.layout')

@section('title', 'Free tools for life in Germany — Expadu')
@section('meta_description', 'Free calculators for newcomers in Germany: Deutschlandticket break-even, permanent-residency timeline, citizenship quiz. No signup, official sources.')
@section('canonical', route('tools.index'))

@section('content')
<section>
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Free · no signup · official sources</span>
            <h2 style="font-size:clamp(1.9rem,3.6vw,2.6rem)">Tools for your life in Germany.</h2>
            <p class="sub">Each one answers a real newcomer question in under a minute — with the source for every number.</p>
        </div>
        <div class="tool-grid">
            <div class="tool">
                <span class="free">transit · 2026 tariff</span>
                <h3>Deutschlandticket break-even</h3>
                <p>Is €63/month worth it for how you actually move? Honest verdict against Cologne's per-ride fares.</p>
                <a href="{{ route('tools.dticket') }}">Check your break-even →</a>
            </div>
            <div class="tool">
                <span class="free">residency · AufenthG</span>
                <h3>Permanent-residency timeline</h3>
                <p>Your earliest Niederlassungserlaubnis date on your track — and what legally speeds it up.</p>
                <a href="{{ route('tools.residency') }}">See your timeline →</a>
            </div>
            <div class="tool">
                <span class="free">citizenship · StAG</span>
                <h3>Citizenship quiz</h3>
                <p>Are you on track for a German passport under the 2024 rules? Five questions, sourced answers.</p>
                <a href="{{ route('tools.citizenship') }}">Take the quiz →</a>
            </div>
        </div>
        <p class="note" style="color:var(--muted);font-size:14px;margin-top:22px">Next up: a netto-brutto salary calculator — shipping once every 2026 tax table is verified. The numbers here follow the same rule as the app: nothing unsourced.</p>
    </div>
</section>
@endsection
