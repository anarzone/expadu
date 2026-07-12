@extends('marketing.layout')

@section('title', $post['title'].' — Expadu')
@section('meta_description', $post['description'])
@section('canonical', route('blog.show', $post['slug']))
@section('og_title', $post['title'])
@section('waitlist_source', 'blog-'.$post['slug'])

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

@section('content')
<article class="wrap prose post">
    <span class="eyebrow">Guide</span>
    <h1>{{ $post['title'] }}</h1>
    <p class="meta">
        Published {{ $post['published_at']->format('j F Y') }}
        @if (! $post['updated_at']->isSameDay($post['published_at']))
            · updated {{ $post['updated_at']->format('j F Y') }}
        @endif
        · by Anar — an expat in Cologne who did all of this the hard way
    </p>

    <div class="post-body">{!! $post['body_html'] !!}</div>

    <div class="tool-cta" style="margin-top:36px">
        <p>Expadu turns guides like this into <b>your personal checklist</b> — deadlines tracked, documents ticked off, offices booked.</p>
        <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
    </div>
</article>
@endsection
