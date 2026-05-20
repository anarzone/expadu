@extends('errors.layout')
@section('title', 'Something went wrong')
@section('content')
    <p class="code">500 · Server error</p>
    <h1>Something on our side broke.</h1>
    <p>We're already looking into it. Try again in a moment — if it keeps happening, drop us a line.</p>
    <div class="actions">
        <a class="btn" href="/dashboard">Back to dashboard</a>
        <a class="btn btn-secondary" href="/">Home</a>
    </div>
@endsection
