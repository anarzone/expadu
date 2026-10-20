<?php

namespace App\Http\Middleware;

use App\Support\MockClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Local only: runs the request at the mock's sample moment when the
 * `mock_clock` cookie asks for one (see App\Support\MockClock), and puts
 * the real clock back afterwards.
 */
class LocalMockClock
{
    public function handle(Request $request, Closure $next): Response
    {
        $at = MockClock::at($request);

        if ($at === null) {
            return $next($request);
        }

        Carbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);

        try {
            return $next($request);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }
}
