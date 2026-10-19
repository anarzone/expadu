@extends('marketing.layout')

@section('tone', 'lilac')
@section('theme_color', '#dccbff')

@section('title', 'Guides for your life in Germany — Expadu Blog')
@section('meta_description', 'Practical English guides for newcomers in Germany — Anmeldung, permits, insurance, transit. Every claim linked to its official source.')
@section('canonical', route('blog.index'))
@section('og_image', asset('marketing/og/guides.png'))
@section('waitlist_source', 'blog')

@section('hero')
    <div class="wrap">
        <div class="sec-head">
            <span class="eyebrow">The Expadu guides</span>
            <h1>Germany, explained in English.</h1>
            <p class="sub">Written from the same sourced catalogue that powers the app — every fee, deadline and document links to the official page.</p>
        </div>
    </div>
@endsection

@section('content')
<section>
    <div class="wrap">
        <div class="post-list">
            @forelse ($posts as $post)
                <a class="post-card" href="{{ route('blog.show', $post['slug']) }}">
                    <span class="mk-kicker">{{ $post['published_at']->format('j M Y') }}</span>
                    <h3>{{ $post['title'] }}</h3>
                    <p>{{ $post['description'] }}</p>
                    <span class="mk-go">Read the guide <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
                </a>
            @empty
                <p class="sub">First guides land this week.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
