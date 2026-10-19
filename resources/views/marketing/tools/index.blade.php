@extends('marketing.layout')

@section('tone', 'yellow')
@section('theme_color', '#ffe363')

@section('title', 'Free tools for life in Germany — Expadu')
@section('meta_description', 'Free calculators for newcomers in Germany: netto-brutto salary, Deutschlandticket break-even, permanent-residency timeline, citizenship quiz. No signup, official sources.')
@section('canonical', route('tools.index'))
@section('og_image', asset('marketing/og/tools.png'))
@section('waitlist_source', 'tools')

@section('hero')
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Free · no signup · official sources</span>
            <h1>Tools for your life in Germany.</h1>
            <p class="sub">Each one answers a real newcomer question in under a minute — with the source for every number.</p>
        </div>
    </div>
@endsection

@section('content')
<section>
    <div class="wrap">
        <div class="tool-grid">
            <a class="tool tool--yellow" href="{{ route('tools.netto') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5h.01M17.5 14.5h.01"/></svg></span>
                <span class="mk-kicker">salary · 2026 tables</span>
                <h3><span class="nobr">Netto-brutto</span> calculator</h3>
                <p>The full 2026 payroll math — all six tax classes, church, kids, statutory or private insurance. Cross-checked against the official BMF calculator.</p>
                <span class="mk-go">Calculate your net <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
            <a class="tool tool--cyan" href="{{ route('tools.dticket') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="13" rx="3"/><path d="M5 11h14M8.5 21l1.5-4M15.5 21l-1.5-4M9 14h.01M15 14h.01"/></svg></span>
                <span class="mk-kicker">transit · 2026 tariff</span>
                <h3>Deutschlandticket <span class="nobr">break-even</span></h3>
                <p>Is €63/month worth it for how you actually move? Honest verdict against Cologne's per-ride fares.</p>
                <span class="mk-go">Check your break-even <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
            <a class="tool tool--lilac" href="{{ route('tools.residency') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16M9.5 16l2 2 3.5-4"/></svg></span>
                <span class="mk-kicker">residency · AufenthG</span>
                <h3><span class="nobr">Permanent-residency</span> timeline</h3>
                <p>Your earliest Niederlassungserlaubnis date — watch the timeline fill, and see what legally speeds it up.</p>
                <span class="mk-go">See your timeline <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
            <a class="tool tool--peach" href="{{ route('tools.citizenship') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M9 17h6"/></svg></span>
                <span class="mk-kicker">citizenship · StAG</span>
                <h3>Citizenship quiz</h3>
                <p>Are you on track for a German passport under the 2024 rules? Five questions, sourced answers.</p>
                <span class="mk-go">Take the quiz <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
        </div>
        <p class="note" style="color:var(--muted);font-size:14px;margin-top:22px">The numbers here follow the same rule as the app: nothing unsourced, and every constant links to where it comes from.</p>
    </div>
</section>
@endsection
