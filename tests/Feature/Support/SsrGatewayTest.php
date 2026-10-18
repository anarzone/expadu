<?php

use App\Support\Ssr\SsrGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\Gateway;

test('Inertia renders through the short-timeout SSR gateway', function () {
    $options = app(Gateway::class)->client()->getOptions();

    expect(app(Gateway::class))->toBeInstanceOf(SsrGateway::class)
        ->and($options['timeout'])->toBe(1.5)
        ->and($options['connect_timeout'])->toBe(0.5);
});

test('a slow or dead SSR server falls back to client-side rendering', function () {
    config(['inertia.ssr.ensure_bundle_exists' => false]);
    Http::fake(fn () => throw new ConnectionException('Operation timed out after 1500 milliseconds'));

    expect(app(Gateway::class)->dispatch(['component' => 'dashboard', 'props' => []]))->toBeNull();
});

test('a healthy SSR server still renders the page', function () {
    config(['inertia.ssr.ensure_bundle_exists' => false]);
    Http::fake(['*/render' => Http::response(['head' => ['<title>Today</title>'], 'body' => '<div id="app"></div>'])]);

    $response = app(Gateway::class)->dispatch(['component' => 'dashboard', 'props' => []]);

    expect($response->head)->toBe('<title>Today</title>')
        ->and($response->body)->toBe('<div id="app"></div>');
});
