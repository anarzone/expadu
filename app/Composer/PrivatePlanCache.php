<?php

namespace App\Composer;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use JsonException;

/** Personal plan snapshots are encrypted JSON scalars, never cached PHP objects. */
final class PrivatePlanCache
{
    public function put(User $user, string $purpose, array $data, int $ttl): void
    {
        abort_unless($this->mayRead($user), 403);
        Cache::put($this->key($user->id, $purpose), Crypt::encryptString(json_encode([
            'schema' => 'composer.private-plan.1', 'owner_id' => $user->id, 'purpose' => $purpose, 'data' => $data,
        ], JSON_THROW_ON_ERROR)), $ttl);
    }

    public function get(User $user, string $purpose): ?array
    {
        if (! $this->mayRead($user)) {
            return null;
        }

        return $this->decode($user->id, $purpose, Cache::get($this->key($user->id, $purpose)));
    }

    public function forget(User $user, string $purpose): void
    {
        Cache::forget($this->key($user->id, $purpose));
    }

    /** Internal erasure worker: exact subject, including an account already deleted. */
    public function purgeAppointmentCopies(int $userId): void
    {
        foreach (['plan', 'today'] as $purpose) {
            $key = $this->key($userId, $purpose);
            $raw = Cache::get($key);
            // Legacy arrays are inspected only for deletion, never served to a user.
            $data = is_array($raw) ? $raw : $this->decode($userId, $purpose, $raw);
            if (is_string($raw) && $data === null) {
                Cache::forget($key);

                continue;
            }
            foreach ($data['slots'] ?? [] as $slot) {
                if (($slot['is_appointment'] ?? false) === true || ($slot['type'] ?? null) === 'appointment') {
                    Cache::forget($key);
                    break;
                }
            }
        }
    }

    private function decode(int $userId, string $purpose, mixed $raw): ?array
    {
        if (! is_string($raw)) {
            return null;
        }
        try {
            $envelope = json_decode(Crypt::decryptString($raw), true, 64, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return null;
        }

        return is_array($envelope) && ($envelope['schema'] ?? null) === 'composer.private-plan.1'
            && ($envelope['owner_id'] ?? null) === $userId && ($envelope['purpose'] ?? null) === $purpose
            && is_array($envelope['data'] ?? null) ? $envelope['data'] : null;
    }

    private function mayRead(User $user): bool
    {
        return User::query()->whereKey($user->id)->whereNotNull('email_verified_at')->exists();
    }

    private function key(int $userId, string $purpose): string
    {
        if (! in_array($purpose, ['plan', 'today'], true)) {
            throw new \InvalidArgumentException('Unsupported private plan cache purpose.');
        }

        return 'composer:'.$purpose.':'.$userId;
    }
}
