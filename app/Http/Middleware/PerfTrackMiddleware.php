<?php

namespace App\Http\Middleware;

use App\Support\PerfLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PerfTrackMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('perf_start', microtime(true));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $start = $request->attributes->get('perf_start');
        if (! is_float($start)) {
            return;
        }

        $route = $request->route()?->getName();
        if (! $route) {
            $route = $request->method().' '.($request->route()?->uri() ?: $request->path());
        }

        PerfLogger::record('route:'.$route, [
            'ms' => (int) round((microtime(true) - $start) * 1000),
            'status' => $response->getStatusCode(),
            'method' => $request->method(),
            'inertia' => $request->header('X-Inertia') ? 1 : 0,
        ]);
    }
}
