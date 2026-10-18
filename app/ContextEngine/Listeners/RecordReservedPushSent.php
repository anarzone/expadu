<?php

namespace App\ContextEngine\Listeners;

use App\ContextEngine\PushLedger;
use App\ContextEngine\ReservedPush;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Consumes a reserved push only once it has actually gone out: the queue
 * worker fires NotificationSent after the channel sends, so a push that is
 * throttled, dropped or still queued never uses up its key.
 */
class RecordReservedPushSent
{
    public function __construct(private PushLedger $pushes) {}

    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        if (! $notification instanceof ReservedPush || $event->channel !== WebPushChannel::class) {
            return;
        }

        if (($reservation = $notification->pushReservation()) !== null) {
            $this->pushes->delivered($reservation);
        }
    }
}
