<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for every response, plus a nonce-based CSP on
 * the marketing site (where all assets are self-hosted and only two inline
 * scripts exist). The app SPA is exempt from CSP for now — Vite dev, map
 * tiles and Inertia need their own carefully-tested policy.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $applyCsp = $this->isMarketingRequest($request);
        $nonce = $applyCsp ? Str::random(24) : null;

        if ($nonce !== null) {
            view()->share('cspNonce', $nonce);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');

        if ($nonce !== null) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data:",
                "font-src 'self'",
                "connect-src 'self'",
                "object-src 'none'",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }

    /**
     * CSP is scoped to the marketing domain and skipped in local dev, where
     * the Vite dev server injects cross-origin module scripts.
     */
    private function isMarketingRequest(Request $request): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        $marketingDomain = config('app.marketing_domain');

        return is_string($marketingDomain)
            && $marketingDomain !== ''
            && $request->getHost() === $marketingDomain;
    }
}
