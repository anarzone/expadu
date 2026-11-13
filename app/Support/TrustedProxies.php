<?php

namespace App\Support;

/**
 * The proxies whose X-Forwarded-For entries we believe, so a request's IP is
 * the visitor's own: per-IP rate limits and the security log both key on it.
 *
 * Production runs Cloudflare → host nginx → the app container. Cloudflare
 * puts the visitor's address in X-Forwarded-For and nginx appends
 * Cloudflare's. Trusting only the nearest hop (the old `at: '*'`) made every
 * visitor look like the Cloudflare server that carried them (13 Nov 2026), so
 * people shared limits and bots spread across Cloudflare got fresh ones.
 *
 * Trusted: private networks (nginx reaches the container over Docker's
 * bridge; local dev is 127.0.0.1) and Cloudflare's published ranges. A
 * forged X-Forwarded-For changes nothing: the first untrusted address from
 * the right is the one Cloudflare or nginx saw.
 */
final class TrustedProxies
{
    /** https://www.cloudflare.com/ips-v4 and /ips-v6, as of 13 Nov 2026. */
    public const Cloudflare = [
        '173.245.48.0/20',
        '103.21.244.0/22',
        '103.22.200.0/22',
        '103.31.4.0/22',
        '141.101.64.0/18',
        '108.162.192.0/18',
        '190.93.240.0/20',
        '188.114.96.0/20',
        '197.234.240.0/22',
        '198.41.128.0/17',
        '162.158.0.0/15',
        '104.16.0.0/13',
        '104.24.0.0/14',
        '172.64.0.0/13',
        '131.0.72.0/22',
        '2400:cb00::/32',
        '2606:4700::/32',
        '2803:f800::/32',
        '2405:b500::/32',
        '2405:8100::/32',
        '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        // PRIVATE_SUBNETS is Symfony's own shorthand for 10/8, 172.16/12,
        // 192.168/16, 127/8, the IPv6 equivalents and the like.
        return ['PRIVATE_SUBNETS', ...self::Cloudflare];
    }
}
