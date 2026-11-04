<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Fortify;

/**
 * How the iPhone app gets a token. Sanctum's sample route checks only email and
 * password; here an account with two-step sign-in gets a short challenge instead, and a
 * token only with its code, exactly as Fortify's web pipeline does for a session.
 */
final class AppSignIn
{
    /** A phone stays signed in this long; it asks for a new token well before. */
    public const TOKEN_DAYS = 60;

    /** "Keep me signed in" off. */
    public const SHORT_TOKEN_HOURS = 24;

    private const CHALLENGE_MINUTES = 5;

    private const CODE_SECONDS = 60;

    /** @return array{token: string, expires_at: string} */
    public function issue(User $user, string $device, bool $remember): array
    {
        $expires = $remember ? now()->addDays(self::TOKEN_DAYS) : now()->addHours(self::SHORT_TOKEN_HOURS);
        $token = $user->createToken($device, ['*'], $expires);

        return ['token' => $token->plainTextToken, 'expires_at' => $expires->toIso8601String()];
    }

    public function challenge(User $user, string $device, bool $remember): string
    {
        $challenge = Str::random(48);
        Cache::put($this->challengeKey($challenge), ['user' => $user->id, 'device' => $device, 'remember' => $remember], now()->addMinutes(self::CHALLENGE_MINUTES));

        return $challenge;
    }

    public function challengedUser(string $challenge): ?User
    {
        $entry = $this->challengeEntry($challenge);

        return $entry ? User::find($entry['user']) : null;
    }

    /**
     * The token for a right code or an unused recovery code, once; null otherwise.
     *
     * @return array{user: User, token: string, expires_at: string}|null
     */
    public function answer(string $challenge, ?string $code, ?string $recoveryCode): ?array
    {
        $entry = $this->challengeEntry($challenge);
        $user = $entry ? User::find($entry['user']) : null;
        if (! $user instanceof User || ! $this->passes($user, $code, $recoveryCode)) {
            return null;
        }

        // Pulled, so two right answers at once can't both get a token.
        if (Cache::pull($this->challengeKey($challenge)) === null) {
            return null;
        }

        return ['user' => $user, ...$this->issue($user, $entry['device'], $entry['remember'])];
    }

    /** A one-time code the app trades for a token, tied to its PKCE challenge (SocialLoginController). */
    public function codeFor(User $user, string $pkceChallenge): string
    {
        $code = Str::random(48);
        Cache::put($this->codeKey($code), ['user' => $user->id, 'pkce' => $pkceChallenge], now()->addSeconds(self::CODE_SECONDS));

        return $code;
    }

    public function exchange(string $code, string $verifier): ?User
    {
        $entry = Cache::pull($this->codeKey($code));
        if (! is_array($entry)) {
            return null;
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        return hash_equals((string) $entry['pkce'], $expected) ? User::find($entry['user']) : null;
    }

    private function passes(User $user, ?string $code, ?string $recoveryCode): bool
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return false;
        }

        if (filled($code)) {
            return app(TwoFactorAuthenticationProvider::class)->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $code);
        }

        if (blank($recoveryCode)) {
            return false;
        }

        $match = collect($user->recoveryCodes())->first(fn (string $saved): bool => hash_equals($saved, $recoveryCode));
        if ($match === null) {
            return false;
        }

        $user->replaceRecoveryCode($match);
        event(new RecoveryCodeReplaced($user, $match));

        return true;
    }

    /** @return array{user: int, device: string, remember: bool}|null */
    private function challengeEntry(string $challenge): ?array
    {
        $entry = $challenge === '' ? null : Cache::get($this->challengeKey($challenge));

        return is_array($entry) ? $entry : null;
    }

    private function challengeKey(string $challenge): string
    {
        return 'app-sign-in:challenge:'.hash('sha256', $challenge);
    }

    private function codeKey(string $code): string
    {
        return 'app-sign-in:code:'.hash('sha256', $code);
    }
}
