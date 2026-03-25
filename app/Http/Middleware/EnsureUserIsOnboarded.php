<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsOnboarded
{
    /**
     * Paths that should be excluded from the onboarding redirect.
     *
     * @var array<int, string>
     */
    protected array $except = [
        'onboarding',
        'onboarding/*',
        'logout',
        'settings/*',
        'email/*',
        'two-factor-challenge',
        'confirm-password',
        'verified-email',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (
            $request->user()
            && ! $request->user()->isOnboarded()
            && ! $this->isExcluded($request)
        ) {
            return redirect()->route('onboarding');
        }

        return $next($request);
    }

    protected function isExcluded(Request $request): bool
    {
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
