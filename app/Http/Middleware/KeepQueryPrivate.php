<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For a page whose query says where someone will be and when (Around here's
 * `near` and `time`): the session remembers the page's address without its
 * query, so the place and the time never reach the session store. See
 * StartSessionKeepingPrivateQueries, which reads the mark this sets.
 */
final class KeepQueryPrivate
{
    public const ATTRIBUTE = 'query_is_private';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        return $next($request);
    }
}
