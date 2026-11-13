<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Production: Cloudflare → host nginx → the container over Docker's bridge
 * (172.17.0.1). Cloudflare puts the visitor in X-Forwarded-For; nginx appends
 * the Cloudflare server (here 172.68.10.20).
 */

beforeEach(function () {
    Route::get('/__client-ip', fn (Request $request) => $request->ip());
});

/** What the app takes as the visitor's IP for this X-Forwarded-For. */
function clientIpFor(string $forwardedFor): string
{
    return test()->withServerVariables(['REMOTE_ADDR' => '172.17.0.1', 'HTTP_X_FORWARDED_FOR' => $forwardedFor])
        ->get('/__client-ip')->assertOk()->getContent();
}

test('a visitor through Cloudflare is seen by their own address, not Cloudflare’s', function () {
    expect(clientIpFor('203.0.113.7, 172.68.10.20'))->toBe('203.0.113.7')
        ->and(clientIpFor('2001:db8::7, 2606:4700::1'))->toBe('2001:db8::7');
});

test('a forged X-Forwarded-For changes nothing', function () {
    // Sent through Cloudflare: Cloudflare appends the real visitor after it.
    expect(clientIpFor('6.6.6.6, 203.0.113.7, 172.68.10.20'))->toBe('203.0.113.7');

    // Sent straight to the server, around Cloudflare: nginx appends the sender.
    expect(clientIpFor('6.6.6.6, 198.51.100.9'))->toBe('198.51.100.9');
});

test('two visitors carried by the same Cloudflare server have their own rate limits', function () {
    $post = fn (string $visitor, string $email): int => test()
        ->withServerVariables(['REMOTE_ADDR' => '172.17.0.1', 'HTTP_X_FORWARDED_FOR' => "{$visitor}, 172.68.10.20"])
        ->postJson('/waitlist', ['email' => $email, 'city' => 'Köln'])->getStatusCode();

    // The waitlist takes 3 posts a minute per IP.
    foreach (range(1, 3) as $i) {
        expect($post('203.0.113.7', "a{$i}@example.com"))->not->toBe(429);
    }
    expect($post('203.0.113.7', 'a4@example.com'))->toBe(429)
        ->and($post('198.51.100.20', 'b@example.com'))->not->toBe(429);
});
