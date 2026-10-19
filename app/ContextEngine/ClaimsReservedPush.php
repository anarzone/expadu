<?php

namespace App\ContextEngine;

use NotificationChannels\WebPush\WebPushChannel;

/**
 * For notifications that carry a PushLedger reservation in a public
 * `?array $pushReservation` property (see ReservedPush).
 */
trait ClaimsReservedPush
{
    /** @return array{key: string, nonce: string, sent_days: int}|null */
    public function pushReservation(): ?array
    {
        return $this->pushReservation;
    }

    /**
     * Runs in the queue worker right before each channel sends. A web push whose
     * key was sent by another job meanwhile (a retry, a lapsed reservation) is
     * dropped, so one reservation never pushes twice.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        if ($channel !== WebPushChannel::class || $this->pushReservation === null) {
            return true;
        }

        return app(PushLedger::class)->claimForSend($this->pushReservation);
    }
}
