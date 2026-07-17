<?php

test('every response carries the baseline security headers', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    expect($response->headers->get('Permissions-Policy'))->toContain('geolocation=(self)');
});

test('the marketing domain gets a nonce-based content security policy', function () {
    config(['app.marketing_domain' => 'marketing.test', 'app.url' => 'https://app.marketing.test']);

    $response = $this->get('http://marketing.test/');

    $response->assertOk();
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toBeNull()
        ->and($csp)->toContain("default-src 'self'")
        ->and($csp)->toContain('nonce-')
        ->and($csp)->toContain("frame-ancestors 'self'")
        // Built assets load from the APP_URL host — the policy must allow it.
        ->and($csp)->toContain("script-src 'self' https://app.marketing.test");

    // The inline theme script must carry the same nonce the header declares.
    preg_match('/nonce-([A-Za-z0-9]+)/', (string) $csp, $matches);
    $response->assertSee('nonce="'.$matches[1].'"', escape: false);
});

test('non-marketing hosts get no CSP header', function () {
    config(['app.marketing_domain' => 'marketing.test']);

    $response = $this->get('http://other.test/');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull();
});
