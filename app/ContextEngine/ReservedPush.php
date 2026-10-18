<?php

namespace App\ContextEngine;

/**
 * A notification whose web push was reserved in the PushLedger. Implemented via
 * ClaimsReservedPush; RecordReservedPushSent marks the reservation delivered.
 */
interface ReservedPush
{
    /** @return array{key: string, nonce: string, sent_days: int}|null */
    public function pushReservation(): ?array;
}
