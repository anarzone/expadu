@extends('marketing.layout')

@section('tone', 'orange')
@section('theme_color', '#ff3902')

@section('title', 'Confirm your spot — Expadu')
@section('meta_description', 'Confirm your place on the Expadu waitlist.')

@push('head')
    <meta name="robots" content="noindex">
@endpush

@section('hero')
    <div class="wrap mk-hero__prose" style="text-align:center">
    <span class="eyebrow">One more step</span>
    <h1>Confirm your spot.</h1>
    </div>
@endsection

@section('content')
<div class="wrap prose" style="text-align:center">
    <p class="sub" style="margin-inline:auto">
        Join the waitlist as <b>{{ $signup->email }}</b>, and you’ll hear the day the new Expadu opens.
    </p>
    <form method="POST" action="{{ $action }}" style="margin-top:26px">
        @csrf
        <button class="btn btn-primary" type="submit">Yes, add me to the list</button>
    </form>
    <p class="meta" style="margin-top:22px">Didn’t sign up? Ignore this page and the email. Nothing happens unless you press the button.</p>
</div>
@endsection
