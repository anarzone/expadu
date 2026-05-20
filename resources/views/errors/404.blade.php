@extends('errors.layout')
@section('title', 'Page not found')
@section('content')
    <p class="code">404 · Not found</p>
    <h1>This page wandered off.</h1>
    <p>The link you followed may be broken, or the page may have moved. Check the URL or head back home.</p>
    <div class="actions">
        <a class="btn" href="/dashboard">Back to dashboard</a>
        <a class="btn btn-secondary" href="/">Home</a>
    </div>
@endsection
