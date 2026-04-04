<?php

namespace App\Listeners;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Notifications\BuergeramtSlotNotification;
use App\Notifications\TransitDisruptionNotification;
use Illuminate\Notifications\Events\NotificationSent;

/**
 * When a notification is sent via the 'database' channel,
 * also create an Alert record so it appears on the alerts page.
 */
class CreateAlertFromNotification
{
    public function handle(NotificationSent $event): void
    {
        // Only create alerts for the database channel (avoid duplicates from WebPush)
        if ($event->channel !== 'database') {
            return;
        }

        $notification = $event->notification;
        $data = $notification->toArray($event->notifiable);

        // Map notification class → alert type
        $alertType = match (true) {
            $notification instanceof BuergeramtSlotNotification => AlertType::System,
            $notification instanceof TransitDisruptionNotification => AlertType::System,
            default => AlertType::System,
        };

        Alert::create([
            'user_id' => $event->notifiable->id,
            'type' => $alertType,
            'title' => $data['title'] ?? $data['type'] ?? 'Notification',
            'body' => $data['body'] ?? $data['summary'] ?? '',
            'deep_link' => $data['url'] ?? '/alerts',
        ]);
    }
}
