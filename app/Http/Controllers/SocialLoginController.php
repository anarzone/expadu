<?php

namespace App\Http\Controllers;

use App\Auth\AppSignIn;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialLoginController extends Controller
{
    /**
     * Google only: no Apple sign-in yet (owner, 19 Oct 2026). Apple's
     * settings stay in config/services.php for when it comes.
     *
     * @var array<int, string>
     */
    public const PROVIDERS = ['google'];

    private const NATIVE = 'native_sign_in';

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * The iPhone app's way in: its web sign-in sheet starts here with a PKCE
     * challenge, and the callback hands back a one-time code instead of a session.
     */
    public function native(Request $request, string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $request->validate(['challenge' => ['required', 'string', 'size:43']]);
        $request->session()->put(self::NATIVE, $request->string('challenge')->value());

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, AppSignIn $app, string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);
        $native = $request->session()->pull(self::NATIVE);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
            if (is_string($native)) {
                return redirect()->away('expadu://auth?error=google');
            }

            // Said on the sign-in poster, in the conflict colour.
            return redirect()->route('login')->with('error', 'Signing in with '.ucfirst($provider).' didn’t finish. Try again, or use your email.');
        }

        $user = User::where('social_provider', $provider)
            ->where('social_id', $socialUser->getId())
            ->first();

        if (! $user && $socialUser->getEmail()) {
            $user = User::where('email', $socialUser->getEmail())->first();

            if ($user) {
                $user->update([
                    'social_provider' => $provider,
                    'social_id' => $socialUser->getId(),
                ]);
            }
        }

        if (! $user) {
            $user = User::create([
                'name' => $socialUser->getName() ?? $socialUser->getNickname() ?? 'User',
                'email' => $socialUser->getEmail(),
                'social_provider' => $provider,
                'social_id' => $socialUser->getId(),
                'email_verified_at' => now(),
            ]);
        }

        if (is_string($native)) {
            return redirect()->away('expadu://auth?code='.$app->codeFor($user, $native));
        }

        Auth::login($user, remember: true);

        // Back to the page "Sign in again" came from, as with a password.
        return redirect()->intended(config('fortify.home', '/today'));
    }
}
