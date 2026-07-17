@extends('marketing.layout')

@section('title', 'Free tools for life in Germany — Expadu')
@section('meta_description', 'Free calculators for newcomers in Germany: netto-brutto salary, Deutschlandticket break-even, permanent-residency timeline, citizenship quiz. No signup, official sources.')
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
            <a class="tool" href="{{ route('tools.netto') }}">
                <span class="free">salary · 2026 tables</span>
                <h3>Netto-brutto calculator</h3>
                <p>The full 2026 payroll math — all six tax classes, church, kids, statutory or private insurance. Cross-checked against the official BMF calculator.</p>
                <span class="post-more">Calculate your net →</span>
            </a>
            <a class="tool" href="{{ route('tools.dticket') }}">
                <span class="free">transit · 2026 tariff</span>
                <h3>Deutschlandticket break-even</h3>
                <p>Is €63/month worth it for how you actually move? Honest verdict against Cologne's per-ride fares.</p>
                <span class="post-more">Check your break-even →</span>
            </a>
            <a class="tool" href="{{ route('tools.residency') }}">
                <span class="free">residency · AufenthG</span>
                <h3>Permanent-residency timeline</h3>
                <p>Your earliest Niederlassungserlaubnis date — watch the timeline fill, and see what legally speeds it up.</p>
                <span class="post-more">See your timeline →</span>
            </a>
            <a class="tool" href="{{ route('tools.citizenship') }}">
                <span class="free">citizenship · StAG</span>
                <h3>Citizenship quiz</h3>
                <p>Are you on track for a German passport under the 2024 rules? Five questions, sourced answers.</p>
                <span class="post-more">Take the quiz →</span>
            </a>
        </div>
        <p class="note" style="color:var(--muted);font-size:14px;margin-top:22px">The numbers here follow the same rule as the app: nothing unsourced, and every constant links to where it comes from.</p>
    </div>
</section>
@endsection
