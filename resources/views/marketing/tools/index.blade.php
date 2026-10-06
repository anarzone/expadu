@extends('marketing.layout')

@section('title', 'Free tools for life in Germany — Expadu')
@section('meta_description', 'Free salary and Deutschlandticket estimates with source links. Legal calculators are awaiting review.')
@section('canonical', route('tools.index'))

@section('content')
<section>
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">Free · no signup · official sources</span>
            <h2 style="font-size:clamp(1.9rem,3.6vw,2.6rem)">Tools for your life in Germany.</h2>
            <p class="sub">Explore the available estimates and their sources. Tools awaiting review are clearly marked below.</p>
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
            <article class="tool">
                <span class="free">Awaiting review</span>
                <h3>Permanent-residency timeline</h3>
                <p>This calculator is unavailable while its information is reviewed.</p>
            </article>
            <article class="tool">
                <span class="free">Awaiting review</span>
                <h3>Citizenship quiz</h3>
                <p>This quiz is unavailable while its information is reviewed.</p>
            </article>
        </div>
        <p class="note" style="color:var(--muted);font-size:14px;margin-top:22px">The numbers here follow the same rule as the app: nothing unsourced, and every constant links to where it comes from.</p>
    </div>
</section>
@endsection
