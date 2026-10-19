<?php

namespace App\ContextEngine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * First-crossing push dedupe, after v2's PlanReminderDelivery: a reservation is
 * not a delivery. An evaluator reserves a push under a key (one bureaucracy
 * tier, one residency milestone), gives it back when the bus strips the push
 * (throttle caps, quiet hours, preference), and only a sent web push consumes
 * the key, for as long as the reservation says. Consuming the key up front, as
 * a bare Cache::add did, lost a throttled push for the whole dedupe period.
 *
 * The reservation travels in the action payload (push_reservation) into the
 * notification, which claims it right before sending (ClaimsReservedPush) and
 * is marked delivered once sent (RecordReservedPushSent).
 *
 * Each step is one atomic Cache::add / get / put, so no lock is needed: a race
 * can at worst reserve a key that was just sent, and claimForSend() then
 * refuses to send it again.
 *
 * @phpstan-type Reservation array{key: string, nonce: string, sent_days: int}
 */
final class PushLedger
{
    private const LEASE_MINUTES = 60;

    /**
     * @return Reservation|null null when the key was already sent or is reserved
     */
    public function reserve(string $key, int $sentDays): ?array
    {
        if ($this->wasSent($key)) {
            return null;
        }

        $nonce = Str::random(40);

        return Cache::add($key.':lease', $nonce, now()->addMinutes(self::LEASE_MINUTES))
            ? ['key' => $key, 'nonce' => $nonce, 'sent_days' => $sentDays]
            : null;
    }

    public function wasSent(string $key): bool
    {
        return Cache::has($key);
    }

    /**
     * A queued push still sends only if its key is unsent and it holds (or can
     * retake) the lease.
     *
     * @param  Reservation  $reservation
     */
    public function claimForSend(array $reservation): bool
    {
        if ($this->wasSent($reservation['key'])) {
            return false;
        }

        Cache::add($reservation['key'].':lease', $reservation['nonce'], now()->addMinutes(self::LEASE_MINUTES));

        return Cache::get($reservation['key'].':lease') === $reservation['nonce'];
    }

    /** @param  Reservation  $reservation */
    public function release(array $reservation): void
    {
        if (Cache::get($reservation['key'].':lease') === $reservation['nonce']) {
            Cache::forget($reservation['key'].':lease');
        }
    }

    /** @param  Reservation  $reservation */
    public function delivered(array $reservation): void
    {
        Cache::put($reservation['key'], true, now()->addDays($reservation['sent_days']));
        $this->release($reservation);
    }
}
