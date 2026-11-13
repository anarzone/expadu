<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Security\SecurityLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account (PeopleActions::suspend) is signed out on its next
 * request, wherever it is: the website ends the session and says why, the
 * iPhone app gets 403 (its tokens are already gone). Sign-in still checks
 * the password first, so this is also what turns a suspended sign-in away.
 */
class EnsureAccountIsActive
{
    public const Message = 'This account is suspended. If you think that’s a mistake, write to hello@expadu.com.';

    public function handle(Request $request, Closure $next): Response
    {
        $api = $request->is('api/*');
        $user = $api ? Auth::guard('sanctum')->user() : Auth::guard('web')->user();

        if (! $user instanceof User || ! $user->isSuspended()) {
            return $next($request);
        }

        app(SecurityLog::class)->record(SecurityLog::Blocked, $user, reason: 'suspended');

        if ($api) {
            $user->currentAccessToken()?->delete();

            return response()->json(['message' => self::Message], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', self::Message);
    }
}
