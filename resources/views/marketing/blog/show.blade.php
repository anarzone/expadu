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
@endphp

@section('hero')
    <header class="article-hero">
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
        <nav class="toc" aria-label="On this page" id="article-toc">
            <strong>On this page</strong>
        </nav>

        <article class="article-body">
            {!! $post['body_html'] !!}

            <div class="tool-cta" style="margin-top:36px">
                <p>Expadu turns guides like this into <b>your personal checklist</b> — deadlines tracked, documents ticked off, offices booked.</p>
                <a class="btn btn-primary" href="#waitlist">Start free</a>
            </div>
        </article>
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

            var headings = body.querySelectorAll('h2');

            if (!headings.length) { toc.hidden = true; return; }

            headings.forEach(function (heading) {
                if (!heading.id) {
                    heading.id = heading.textContent.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
                }

                var link = document.createElement('a');
                link.href = '#' + heading.id;
                link.textContent = heading.textContent;
                toc.appendChild(link);
            });
        })();
    </script>
@endpush
