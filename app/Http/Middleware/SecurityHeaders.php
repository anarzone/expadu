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

            // URL::forceRootUrl pins generated URLs to APP_URL (the app
            // subdomain), which makes Vite bundles cross-origin here — and
            // cross-origin module scripts need CORS headers nginx doesn't
            // send for /build. Root assets at the marketing origin instead;
            // route() URLs keep the forced app root for auth links.
            config(['app.asset_url' => $request->getSchemeAndHttpHost()]);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()');

        if ($nonce !== null) {
            // Vite assets are served from the APP_URL host (the app subdomain),
            // so the marketing origin must allow that host explicitly.
            $assetHost = $this->assetHost($request);

            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                trim("script-src 'self' {$assetHost} 'nonce-{$nonce}'"),
                trim("style-src 'self' {$assetHost} 'unsafe-inline'"),
                trim("img-src 'self' {$assetHost} data:"),
                trim("font-src 'self' {$assetHost}"),
                trim("connect-src 'self' {$assetHost}"),
                "object-src 'none'",
                "frame-ancestors 'self'",
                "base-uri 'self'",
                "form-action 'self'",
            ]));
        }

        return $response;
    }

    /**
     * The origin our built assets load from (empty when it matches the
     * request host, so the directive collapses to 'self').
     */
    private function assetHost(Request $request): string
    {
        $appUrlHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($appUrlHost) || $appUrlHost === '' || $appUrlHost === $request->getHost()) {
            return '';
        }

        return 'https://'.$appUrlHost;
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
