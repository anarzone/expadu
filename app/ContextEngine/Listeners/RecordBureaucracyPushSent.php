<?php

namespace App\ContextEngine\Listeners;

use App\ContextEngine\BureaucracyPushLedger;
use App\Notifications\BureaucracyDeadlineNotification;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\WebPushChannel;

/**
 * Consumes a bureaucracy tier only once its web push has actually gone out —
 * the queue worker fires NotificationSent after the channel sends, so a push
 * that is throttled, dropped or still queued never uses up its tier.
 */
class RecordBureaucracyPushSent
{
    public function __construct(private BureaucracyPushLedger $pushes) {}

    public function handle(NotificationSent $event): void
    {
        $notification = $event->notification;

        if (! $notification instanceof BureaucracyDeadlineNotification || $event->channel !== WebPushChannel::class) {
            return;
        }

        if ($notification->userTaskId === null || $notification->pushReservation === null) {
            return;
        }

        $this->pushes->delivered($notification->userTaskId, $notification->tier, $notification->pushReservation);
    }
}
