<?php

namespace App\Listeners;

use App\Bureaucracy\Reminders\BureaucracyWebPushMessage;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Models\User;
use App\Notifications\BureaucracyPlanNotification;
use App\Support\NotificationThrottle;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use NotificationChannels\WebPush\Events\NotificationSent as TransportSucceeded;
use NotificationChannels\WebPush\WebPushChannel;

final class RecordBureaucracyReminderDelivery
{
    public function handleSuccess(TransportSucceeded $event): void
    {
        if (! $event->message instanceof BureaucracyWebPushMessage || ! $event->report->isSuccess()) {
            return;
        }
        $user = $event->subscription->subscribable;
        $reference = $event->message->reference();
        if ($user instanceof User && ($reference['recipient_id'] ?? null) === $user->id
            && app(PlanReminderDelivery::class)->delivered($reference)) {
            NotificationThrottle::recordSent($user);
        }
    }

    /** Laravel's event means the channel returned, not that a transport succeeded. */
    public function handleCompletion(NotificationSent|NotificationFailed $event): void
    {
        if ($event->notification instanceof BureaucracyPlanNotification && $event->channel === WebPushChannel::class) {
            app(PlanReminderDelivery::class)->release($event->notification->reference);
        }
    }
}
