<?php

namespace App\Listeners;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Notifications\BuergeramtSlotNotification;
use App\Notifications\EventReminderNotification;
use App\Notifications\GenericAlertNotification;
use App\Notifications\RhineFloodNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use App\Notifications\WeatherAlertNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Cache;

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

        // Dedup: use notification ID + user to prevent double-firing
        $dedupKey = 'alert_created:'.$event->notification->id.':'.$event->notifiable->id;
        if (Cache::has($dedupKey)) {
            return;
        }
        Cache::put($dedupKey, true, 60);

        $notification = $event->notification;
        $data = $notification->toArray($event->notifiable);

        // Map notification class → alert type
        $alertType = match (true) {
            $notification instanceof EventReminderNotification => AlertType::Reminder,
            $notification instanceof BuergeramtSlotNotification,
            $notification instanceof TransitDisruptionNotification,
            $notification instanceof TransitDelayNotification,
            $notification instanceof RhineFloodNotification,
            $notification instanceof WeatherAlertNotification => AlertType::System,
            // GenericAlertNotification carries its own alert_type
            $notification instanceof GenericAlertNotification => AlertType::tryFrom($data['alert_type'] ?? 'system') ?? AlertType::System,
            default => AlertType::System,
        };

        $title = $data['title'] ?? $data['type'] ?? 'Notification';
        $userId = $event->notifiable->id;

        // DB-level dedup: skip if same title for same user within last 24h
        $exists = Alert::where('user_id', $userId)
            ->where('title', $title)
            ->where('created_at', '>=', now()->subDay())
            ->exists();

        if ($exists) {
            return;
        }

        Alert::create([
            'user_id' => $userId,
            'type' => $alertType,
            'title' => $title,
            'body' => $data['body'] ?? $data['summary'] ?? '',
            'deep_link' => $data['url'] ?? null,
        ]);
    }
}
