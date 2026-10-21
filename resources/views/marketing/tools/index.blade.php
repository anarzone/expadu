@extends('marketing.layout')

@section('tone', 'yellow')
@section('theme_color', '#ffe363')

@section('title', 'Free tools for life in Germany — Expadu')
@section('meta_description', 'Free salary and Deutschlandticket estimates with source links. Legal calculators are awaiting review.')
@section('canonical', route('tools.index'))
@section('og_image', asset('marketing/og/tools.png'))
@section('waitlist_source', 'tools')

@section('hero')
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Free · no signup · official sources</span>
            <h1>Tools for your life in Germany.</h1>
            <p class="sub">Explore the available estimates and their sources. Tools awaiting review are clearly marked below.</p>
        </div>
    </div>
@endsection

@section('content')
<section>
    <div class="wrap">
        <div class="tool-grid">
            <a class="tool tool--yellow" href="{{ route('tools.netto') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5h.01M17.5 14.5h.01"/></svg></span>
                <h3><span class="nobr">Netto-brutto</span> calculator</h3>
                <p>The full 2026 payroll math — all six tax classes, church, kids, statutory or private insurance. Cross-checked against the official BMF calculator.</p>
                <span class="mk-go" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
            <a class="tool tool--cyan" href="{{ route('tools.dticket') }}">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="4" width="14" height="13" rx="3"/><path d="M5 11h14M8.5 21l1.5-4M15.5 21l-1.5-4M9 14h.01M15 14h.01"/></svg></span>
                <h3>Deutschlandticket <span class="nobr">break-even</span></h3>
                <p>Is €63/month worth it for how you actually move? Honest verdict against Cologne's per-ride fares.</p>
                <span class="mk-go" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </a>
            <article class="tool tool--lilac">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16M9.5 16l2 2 3.5-4"/></svg></span>
                <span class="mk-kicker">Awaiting review</span>
                <h3><span class="nobr">Permanent-residency</span> timeline</h3>
                <p>This calculator is unavailable while its information is reviewed.</p>
            </article>
            <article class="tool tool--peach">
                <span class="tool__ico"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="2"/><circle cx="12" cy="10" r="3"/><path d="M9 17h6"/></svg></span>
                <span class="mk-kicker">Awaiting review</span>
                <h3>Citizenship quiz</h3>
                <p>This quiz is unavailable while its information is reviewed.</p>
            </article>
        </div>
        <p class="note" style="color:var(--muted);font-size:14px;margin-top:22px">The numbers here follow the same rule as the app: nothing unsourced, and every constant links to where it comes from.</p>
    </div>
</section>
@endsection
