<?php

namespace App\ContextEngine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * First-crossing push dedupe for bureaucracy tiers, after v2's
 * PlanReminderDelivery: a reservation is not a delivery. The evaluator
 * reserves a (user task, tier) push, gives it back when the bus strips the
 * push (throttle caps, quiet hours, preference), and only a sent web push
 * consumes the tier for 30 days. The old Cache::add consumed it before the
 * bus could strip the push, so a throttled push was lost for a month.
 *
 * Each step is one atomic Cache::add / get / put, so no lock is needed: a race
 * can at worst reserve a tier that was just sent, and claimForSend() then
 * refuses to send it again.
 */
final class BureaucracyPushLedger
{
    private const SENT_DAYS = 30;

    private const LEASE_MINUTES = 60;

    /** A nonce that owns this tier's push, or null when it was sent or is already reserved. */
    public function reserve(int $userTaskId, string $tier): ?string
    {
        $key = $this->key($userTaskId, $tier);
        if (Cache::has($key)) {
            return null;
        }

        $nonce = Str::random(40);

        return Cache::add($key.':lease', $nonce, now()->addMinutes(self::LEASE_MINUTES)) ? $nonce : null;
    }

    /** A queued push still sends only if its tier is unsent and it holds (or can retake) the lease. */
    public function claimForSend(int $userTaskId, string $tier, string $nonce): bool
    {
        $key = $this->key($userTaskId, $tier);
        if (Cache::has($key)) {
            return false;
        }

        Cache::add($key.':lease', $nonce, now()->addMinutes(self::LEASE_MINUTES));

        return Cache::get($key.':lease') === $nonce;
    }

    public function release(int $userTaskId, string $tier, string $nonce): void
    {
        $key = $this->key($userTaskId, $tier);
        if (Cache::get($key.':lease') === $nonce) {
            Cache::forget($key.':lease');
        }
    }

    public function delivered(int $userTaskId, string $tier, string $nonce): void
    {
        $key = $this->key($userTaskId, $tier);
        Cache::put($key, true, now()->addDays(self::SENT_DAYS));
        $this->release($userTaskId, $tier, $nonce);
    }

    /** The pre-ledger key, so markers the old Cache::add wrote still count as sent. */
    private function key(int $userTaskId, string $tier): string
    {
        return "bureaucracy_pushed:{$userTaskId}:{$tier}";
    }
}
