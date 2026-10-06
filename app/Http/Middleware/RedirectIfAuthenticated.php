<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Bounce an already-authenticated user away from the guest-only screens
     * (login, register, password reset) to their real home — but with a
     * visible flash, so the redirect is never silent. Without this, a logged-in
     * user who revisits /login or /register reaches the app with no
     * explanation, which reads as "nothing happened".
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                $home = route('dashboard');

                return redirect($home)->with('status', "You're already signed in.");
            }
        }

        return $next($request);
    }
}
