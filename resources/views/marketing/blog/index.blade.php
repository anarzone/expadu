@extends('marketing.layout')

@section('tone', 'lilac')
@section('theme_color', '#dccbff')

@section('title', 'Guides for your life in Germany — Expadu Blog')
@section('meta_description', 'Practical English guides for newcomers in Germany — Anmeldung, permits, insurance, transit. Every claim linked to its official source.')
@section('canonical', route('blog.index'))
@section('og_image', asset('marketing/og/guides.png'))
@section('waitlist_source', 'blog')

@section('hero')
    <div class="wrap" style="max-width:760px">
        <div class="sec-head">
            <span class="eyebrow">The Expadu guides</span>
            <h1>Germany, explained in English.</h1>
            <p class="sub">Written from the same sourced catalogue that powers the app — every fee, deadline and document links to the official page.</p>
        </div>
    </div>
@endsection

@section('content')
<section>
    <div class="wrap" style="max-width:760px">
        <div class="post-list">
            @forelse ($posts as $post)
                <a class="post-card" href="{{ route('blog.show', $post['slug']) }}">
                    <span class="post-date">{{ $post['published_at']->format('j M Y') }}</span>
                    <h3>{{ $post['title'] }}</h3>
                    <p>{{ $post['description'] }}</p>
                    <span class="post-more">Read the guide →</span>
                </a>
            @empty
                <p class="sub">First guides land this week.</p>
            @endforelse
        </div>
    </div>
</section>
@endsection
