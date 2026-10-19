@extends('marketing.layout')

@section('tone', 'orange')
@section('theme_color', '#ff3902')

@section('title', 'You’re on the list — Expadu')
@section('meta_description', 'Waitlist signup confirmed.')

@section('hero')
    <div class="wrap mk-hero__prose" style="text-align:center">
    <span class="eyebrow">Confirmed</span>
    <h1>You’re on the list.</h1>
    </div>
@endsection

@section('content')
<div class="wrap prose" style="text-align:center">
    <p class="sub" style="margin-inline:auto">
        The day the new Expadu opens, you’ll be the first to know.
        Until then — the free tools already work everywhere in Germany.
    </p>
    <div style="margin-top:26px">
        <a class="btn btn-primary" href="{{ route('home') }}">Back to Expadu</a>
    </div>
</div>
@endsection
