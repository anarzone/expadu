@extends('marketing.layout')

@section('title', 'German citizenship quiz — are you on track? (2024 rules) — Expadu')
@section('meta_description', 'Five questions against the reformed StAG: 5-year standard track, 3-year fast track, spouse route — and dual citizenship. With sources, no signup.')
@section('canonical', route('tools.citizenship'))
@section('waitlist_source', 'tool-citizenship')

@push('head')
    @vite('resources/js/marketing-tools.ts')
@endpush

@push('structured-data')
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebApplication',
        'name' => 'German citizenship eligibility quiz',
        'applicationCategory' => 'UtilitiesApplication',
        'operatingSystem' => 'Web',
        'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'EUR'],
        'url' => route('tools.citizenship'),
    ], JSON_UNESCAPED_SLASHES) !!}</script>
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">Free tool · StAG, 2024 reform</span>
        <h1>Are you on track for a German passport?</h1>
        <p class="sub">Five questions against the current rules — since 2024 you generally keep your original citizenship.</p>

        <script type="application/json" id="tool-data">@json($rules)</script>

        <div class="tool-form" id="tool-citizenship">
            <div class="quiz-prog"><i id="cz-prog" style="width:20%"></i></div>

            <div class="quiz-step on" data-s="0">
                <div class="quiz-q">How many years have you lived legally in Germany?</div>
                <div class="quiz-opts">
                    <button type="button" data-k="years" data-v="1">Under 3 years</button>
                    <button type="button" data-k="years" data-v="3">3 – 4 years</button>
                    <button type="button" data-k="years" data-v="5">{{ $rules['standard_years'] }} years or more</button>
                </div>
            </div>
            <div class="quiz-step" data-s="1">
                <div class="quiz-q">Are you married to a German citizen ({{ $rules['spouse_marriage_years'] }}+ years)?</div>
                <div class="quiz-opts">
                    <button type="button" data-k="married" data-v="1">Yes</button>
                    <button type="button" data-k="married" data-v="0">No</button>
                </div>
            </div>
            <div class="quiz-step" data-s="2">
                <div class="quiz-q">Your German level?</div>
                <div class="quiz-opts">
                    <button type="button" data-k="german" data-v="none">Below B1 (yet)</button>
                    <button type="button" data-k="german" data-v="b1">B1 certified</button>
                    <button type="button" data-k="german" data-v="c1">C1 certified</button>
                </div>
            </div>
            <div class="quiz-step" data-s="3">
                <div class="quiz-q">Do you support yourself without basic benefits?</div>
                <div class="quiz-opts">
                    <button type="button" data-k="livelihood" data-v="1">Yes</button>
                    <button type="button" data-k="livelihood" data-v="0">Not fully</button>
                </div>
            </div>
            <div class="quiz-step" data-s="4">
                <div class="quiz-q">Naturalisation test?</div>
                <div class="quiz-opts">
                    <button type="button" data-k="test" data-v="1">Passed</button>
                    <button type="button" data-k="test" data-v="0">Not yet (33 questions, bookable at the VHS)</button>
                </div>
            </div>
            <button type="button" class="quiz-back" id="cz-back" hidden>← back</button>
        </div>

        <div class="tool-result" id="cz-result" aria-live="polite"></div>

        <div class="tool-foot">
            <p><b>A guide, not legal advice.</b> The {{ $rules['fast_years'] }}-year fast track additionally needs special integration achievements only the Behörde can assess; convictions block naturalisation.</p>
            <p class="sources">Sources:
                @foreach ($rules['sources'] as $source)
                    <a href="{{ $source['url'] }}" rel="noopener" target="_blank">{{ $source['label'] }}</a>@if (! $loop->last) · @endif
                @endforeach
            </p>
        </div>

        <div class="tool-cta">
            <p>Expadu tracks the whole road there — permits, deadlines, paperwork, in English.</p>
            <a class="btn btn-primary" href="{{ route('register') }}">Start free</a>
        </div>
    </div>
</section>
@endsection
