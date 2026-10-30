@extends('marketing.layout')

@section('tone', 'lilac')
@section('theme_color', '#dccbff')

@section('title', $post['title'].' — Expadu')
@section('meta_description', $post['description'])
@section('canonical', route('blog.show', $post['slug']))
@section('og_title', $post['title'])
@php
    $ogImage = is_file(public_path("marketing/og/guide-{$post['slug']}.png")) ? "marketing/og/guide-{$post['slug']}.png" : 'marketing/og-landing.png';
@endphp
@section('og_image', asset($ogImage))
@section('waitlist_source', 'blog-'.$post['slug'])

@push('styles')
    @vite('resources/css/marketing-guide.css')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $post['title'],
        'description' => $post['description'],
        'datePublished' => $post['published_at']->toDateString(),
        'dateModified' => $post['updated_at']->toDateString(),
        'author' => ['@type' => 'Person', 'name' => 'Anar — founder, Expadu'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Expadu', 'url' => route('home')],
        'mainEntityOfPage' => route('blog.show', $post['slug']),
        'image' => asset($ogImage),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
    {{-- The trail behind the header's back link: Guides, then this guide. --}}
    <script type="application/ld+json">{!! json_encode([
        '@@context' => 'https://schema.org',
        '@type' => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Guides', 'item' => route('blog.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $post['title'], 'item' => route('blog.show', $post['slug'])],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@php
    $readMinutes = max(1, (int) round(str_word_count(strip_tags($post['body_html'])) / 200));
    // The guide's "## Sources" section gets its own card at the end.
    [$bodyHtml, $sourcesHtml] = array_pad(explode('<h2>Sources</h2>', $post['body_html'], 2), 2, null);
    $glance = $post['glance'] ?? [];
    $glanceIcons = [
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'calendar' => '<rect x="4" y="5" width="16" height="16" rx="2"/><path d="M16 3v4M8 3v4M4 11h16"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'euro' => '<path d="M17 7.5A6 6 0 1 0 17 16.5M5 10.5h8M5 13.5h8"/>',
        'document' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'check' => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    ];
@endphp

@section('hero')
    <header class="article-hero{{ $glance ? ' has-glance' : '' }}">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('blog.index') }}"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>Guides</a>
            </nav>
            <h1>{{ \App\Marketing\GermanCompounds::softHyphens($post['title']) }}</h1>
            <p class="dek">{{ $post['description'] }}</p>
            <div class="byline">
                <span>{{ $readMinutes }} min read</span><span>By Anar · expat in Cologne</span><span class="checked">Official sources checked {{ $post['updated_at']->format('j F Y') }}</span>
            </div>
        </div>
    </header>
@endsection

@section('content')
<main id="main">

    <div class="wrap article-layout">
        <article class="article-body">
            {!! $bodyHtml !!}

            @if ($sourcesHtml)
                <section class="article-sources" aria-labelledby="sources">
                    <h2 id="sources"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>Sources</h2>
                    {!! $sourcesHtml !!}
                </section>
            @endif

            <div class="guide-cta">
                <strong>Turn this guide into your checklist</strong>
                <p>Deadlines tracked, documents ticked off, offices booked.</p>
                <a href="#waitlist">Start free <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
            </div>
        </article>

        {{-- Beside the guide on desktop: the facts most readers came for, which
             start up next to the title and stay in view, with the contents under
             them. On a phone the facts sit under the title and scroll away. --}}
        <aside class="article-rail">
            @if ($glance)
                <section class="glance" aria-labelledby="glance-title">
                    <h2 id="glance-title">At a glance</h2>
                    <ul>
                        @foreach ($glance as $fact)
                            <li>
                                <span class="glance__ico"><svg viewBox="0 0 24 24" aria-hidden="true">{!! $glanceIcons[$fact['icon']] ?? $glanceIcons['check'] !!}</svg></span>
                                <span><strong>{{ $fact['title'] }}</strong><span>{{ $fact['detail'] }}</span></span>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
            <nav class="toc" aria-label="On this page" id="article-toc">
                <strong>On this page</strong>
            </nav>
        </aside>
    </div>
</main>
@endsection

@push('scripts')
    <script @if (! empty($cspNonce)) nonce="{{ $cspNonce }}" @endif>
        (function () {
            var body = document.querySelector('.article-body');
            var toc = document.getElementById('article-toc');

            if (!body || !toc) { return; }

            // A table too wide for the screen becomes one card per row, each
            // value labelled with its column header (see .is-stacked).
            var tables = body.querySelectorAll('table');

            tables.forEach(function (table) {
                var heads = table.querySelectorAll('thead th');

                table.querySelectorAll('tbody tr').forEach(function (row) {
                    row.querySelectorAll('td').forEach(function (cell, i) {
                        var label = heads[i] ? heads[i].textContent.trim() : '';

                        if (label) { cell.setAttribute('data-label', label); }
                    });
                });
            });

            function fitTables() {
                tables.forEach(function (table) {
                    table.classList.remove('is-stacked');

                    if (table.scrollWidth > table.clientWidth + 1) { table.classList.add('is-stacked'); }
                });
            }

            fitTables();
            window.addEventListener('resize', fitTables);

            if (document.fonts) { document.fonts.ready.then(fitTables); }

            // The facts card starts level with the back link, lifted over the
            // band (desktop only; without this it simply starts below the band).
            var rail = document.querySelector('.article-rail');
            var back = document.querySelector('.article-hero .breadcrumb a');
            var band = document.querySelector('.mk-band');

            function liftRail() {
                if (!rail || !back || !band) { return; }

                rail.style.removeProperty('--rail-lift');

                if (window.innerWidth <= 900 || !rail.querySelector('.glance')) { return; }

                var backInBand = back.getBoundingClientRect().top - band.getBoundingClientRect().top;
                var railStart = body.getBoundingClientRect().top + window.scrollY;

                rail.style.setProperty('--rail-lift', Math.round(backInBand - railStart) + 'px');
            }

            liftRail();
            window.addEventListener('resize', liftRail);

            if (document.fonts) { document.fonts.ready.then(liftRail); }

            var headings = body.querySelectorAll('h2');

            if (!headings.length) { toc.hidden = true; return; }

            var links = [];

            headings.forEach(function (heading) {
                if (!heading.id) {
                    heading.id = heading.textContent.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                }

                var link = document.createElement('a');
                link.href = '#' + heading.id;
                link.textContent = heading.textContent;
                toc.appendChild(link);
                links.push(link);
            });

            // The contents list marks the section you're reading.
            var ticking = false;

            function markCurrent() {
                ticking = false;
                var current = 0;

                headings.forEach(function (heading, i) {
                    if (heading.getBoundingClientRect().top < 160) { current = i; }
                });

                links.forEach(function (link, i) {
                    if (i === current) { link.setAttribute('aria-current', 'location'); } else { link.removeAttribute('aria-current'); }
                });
            }

            markCurrent();
            window.addEventListener('scroll', function () {
                if (!ticking) { ticking = true; window.requestAnimationFrame(markCurrent); }
            }, { passive: true });
        })();
    </script>
@endpush
