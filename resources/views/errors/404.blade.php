@extends('errors.layout')
@section('title', 'Page not found')
@section('content')
    <p class="code">404 · Not found</p>
    <h1>This page wandered off.</h1>
    <p>The link you followed may be broken, or the page may have moved. Check the URL or head back home.</p>
    <div class="actions">
        {{-- The marketing host has no dashboard; /dashboard there is another 404. --}}
        @if (request()->getHost() === config('app.marketing_domain'))
            <a class="btn" href="/">Back to home</a>
        @else
            <a class="btn" href="/dashboard">Back to dashboard</a>
            <a class="btn btn-secondary" href="/">Home</a>
        @endif
    </div>
@endsection
