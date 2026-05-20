@extends('errors.layout')
@section('title', 'Session expired')
@section('content')
    <p class="code">419 · Session expired</p>
    <h1>Your session timed out.</h1>
    <p>For your security, you've been logged out. Sign in again to continue.</p>
    <div class="actions">
        <a class="btn" href="/login">Log in</a>
    </div>
@endsection
