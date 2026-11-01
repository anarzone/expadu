@extends('marketing.layout')

@section('title', $toolName.' — awaiting review — Expadu')
@section('meta_description', 'This tool is temporarily unavailable while its information is reviewed.')

@push('head')
    <meta name="robots" content="noindex, follow">
@endpush

@section('content')
<section>
    <div class="wrap tool-shell">
        <span class="eyebrow">{{ $toolName }}</span>
        <h1>This tool is awaiting review</h1>
        <p class="sub">We’re checking the information behind this tool. It won’t calculate a result or ask for your details until that review is complete.</p>
        <a class="btn btn-primary" href="{{ route('tools.index') }}">See available tools →</a>
    </div>
</section>
@endsection
