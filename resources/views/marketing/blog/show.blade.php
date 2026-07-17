@extends('marketing.layout')

@section('title', $post['title'].' — Expadu')
@section('meta_description', $post['description'])
@section('canonical', route('blog.show', $post['slug']))
@section('og_title', $post['title'])
@section('waitlist_source', 'blog-'.$post['slug'])

@push('styles')
    @vite('resources/css/marketing-guide.css')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $post['title'],
        'description' => $post['description'],
        'datePublished' => $post['published_at']->toDateString(),
        'dateModified' => $post['updated_at']->toDateString(),
        'author' => ['@type' => 'Person', 'name' => 'Anar — founder, Expadu'],
        'publisher' => ['@type' => 'Organization', 'name' => 'Expadu', 'url' => route('home')],
        'mainEntityOfPage' => route('blog.show', $post['slug']),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endpush

@php
    $readMinutes = max(1, (int) round(str_word_count(strip_tags($post['body_html'])) / 200));
@endphp

@section('content')
<main id="main">
    <header class="article-hero">
        <div class="wrap">
            <nav class="breadcrumb" aria-label="Breadcrumb">
                <a href="{{ route('blog.index') }}">Guides</a><span aria-hidden="true">/</span><span>{{ \Illuminate\Support\Str::limit($post['title'], 42) }}</span>
            </nav>
            <span class="eyebrow">Guide</span>
            <h1>{{ $post['title'] }}</h1>
            <p class="dek">{{ $post['description'] }}</p>
            <div class="byline">
                <span>{{ $readMinutes }} min read</span><span>By Anar · expat in Cologne</span><span class="checked">Official sources checked {{ $post['updated_at']->format('j F Y') }}</span>
            </div>
        </div>
    </header>

    <div class="wrap article-layout">
        <nav class="toc" aria-label="On this page" id="article-toc">
            <strong>On this page</strong>
        </nav>

        <article class="article-body">
            {!! $post['body_html'] !!}

            <div class="tool-cta" style="margin-top:36px">
                <p>Expadu turns guides like this into <b>your personal checklist</b> — deadlines tracked, documents ticked off, offices booked.</p>
                <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
            </div>
        </article>
    </div>
</main>
@endsection

@push('scripts')
    <script>
        (function () {
            var body = document.querySelector('.article-body');
            var toc = document.getElementById('article-toc');

            if (!body || !toc) { return; }

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
