<?php

namespace App\Http\Controllers;

use App\Models\User;
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

    public function redirect(string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(string $provider)
    {
        abort_unless(in_array($provider, self::PROVIDERS, true), 404);

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (\Throwable) {
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

        Auth::login($user, remember: true);

        return redirect(config('fortify.home', '/dashboard'));
    }
}
