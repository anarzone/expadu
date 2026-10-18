<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One address per page: 301s duplicate URLs to the one search engines should
 * index. Only GET and HEAD are touched; a redirected POST would lose its body.
 */
class CanonicalRedirects
{
    /**
     * Fortify registers these domain-less, so they also answer on the
     * marketing host. They belong to the app.
     */
    private const APP_PATHS = ['login', 'register'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $next($request);
        }

        if ($target = $this->appHostTarget($request) ?? $this->slashlessTarget($request)) {
            return new RedirectResponse($target, 301);
        }

        return $next($request);
    }

    private function appHostTarget(Request $request): ?string
    {
        $appDomain = config('app.app_domain');
        $marketingDomain = config('app.marketing_domain');

        if (! $appDomain || ! $marketingDomain || $request->getHost() !== $marketingDomain) {
            return null;
        }

        if (! in_array($request->path(), self::APP_PATHS, true)) {
            return null;
        }

        $query = $request->getQueryString();

        return $request->getScheme().'://'.$appDomain.'/'.$request->path().($query !== null ? '?'.$query : '');
    }

    /**
     * The router ignores a trailing slash, so /blog/ answered 200 as a copy of
     * /blog. Send it to the slashless form on the same host.
     */
    private function slashlessTarget(Request $request): ?string
    {
        $path = $request->getPathInfo();

        if ($path === '/' || ! str_ends_with($path, '/')) {
            return null;
        }

        $query = $request->getQueryString();

        // Exactly one leading slash: "//evil.example/" must not turn into a
        // protocol-relative redirect to another host.
        return '/'.trim($path, '/').($query !== null ? '?'.$query : '');
    }
}
