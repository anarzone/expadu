<?php

namespace App\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks the identity token from the iPhone's Sign in with Apple sheet: signed by one of Apple's
 * published keys, issued by Apple, for this app, unexpired, and answering the nonce the app sent.
 */
class AppleIdentity
{
    public const ISSUER = 'https://appleid.apple.com';

    public const KEYS_URL = 'https://appleid.apple.com/auth/keys';

    private const KEYS_CACHE = 'auth:apple-keys';

    /**
     * @return array{sub: string, email: ?string, email_verified: bool}|null null when the token can't be trusted
     */
    public function verify(string $identityToken, string $nonce): ?array
    {
        $claims = $this->decode($identityToken, refetch: false) ?? $this->decode($identityToken, refetch: true);
        if ($claims === null) {
            return null;
        }

        $audience = (array) ($claims->aud ?? []);
        if (($claims->iss ?? null) !== self::ISSUER
            || ! in_array(config('services.apple.bundle_id'), $audience, true)
            || ! is_string($claims->sub ?? null) || $claims->sub === ''
            || ! is_string($claims->nonce ?? null)
            || ! hash_equals($claims->nonce, hash('sha256', $nonce))) {
            return null;
        }

        $email = is_string($claims->email ?? null) ? $claims->email : null;
        $verified = in_array($claims->email_verified ?? false, [true, 'true'], true);

        return ['sub' => $claims->sub, 'email' => $email, 'email_verified' => $email !== null && $verified];
    }

    /** Signature and expiry; Apple rotates its keys, so an unknown key id fetches them once more. */
    private function decode(string $token, bool $refetch): ?object
    {
        $keys = $this->keys($refetch);
        if ($keys === null) {
            return null;
        }

        try {
            return JWT::decode($token, JWK::parseKeySet($keys, 'RS256'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed>|null */
    private function keys(bool $refetch): ?array
    {
        if ($refetch) {
            Cache::forget(self::KEYS_CACHE);
        }

        $keys = Cache::remember(self::KEYS_CACHE, now()->addHours(12), function () {
            $response = Http::timeout(5)->acceptJson()->get(self::KEYS_URL);

            return $response->successful() && is_array($response->json('keys')) ? $response->json() : false;
        });

        if ($keys === false) {
            Cache::forget(self::KEYS_CACHE);

            return null;
        }

        return $keys;
    }
}
