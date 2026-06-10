<?php

namespace App\Listeners;

use App\Enums\AlertType;
use App\Models\Alert;
use App\Notifications\BuergeramtSlotNotification;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\EventReminderNotification;
use App\Notifications\MarketClosureNotification;
use App\Notifications\RhineFloodNotification;
use App\Notifications\TransitDelayNotification;
use App\Notifications\TransitDisruptionNotification;
use App\Notifications\WeatherAlertNotification;
use App\Support\RedisLogger;
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
        // Skip framework notifications (password reset, email verification, etc.)
        if (! method_exists($event->notification, 'toArray')) {
            return;
        }

        // Log every notification delivery (all channels)
        RedisLogger::log('notification_delivery_log', [
            'user_id' => $event->notifiable->id,
            'channel' => $event->channel,
            'type' => get_class($event->notification),
            'title' => $event->notification->toArray($event->notifiable)['title'] ?? null,
        ]);

        // Only create alerts for the database channel (avoid duplicates from WebPush)
        if ($event->channel !== 'database') {
            return;
        }

        // Dedup: hash notification class + user + title to prevent double-firing
        $data = $event->notification->toArray($event->notifiable);
        $dedupKey = 'alert_created:'.md5(
            get_class($event->notification).':'.$event->notifiable->id.':'.($data['title'] ?? '')
        );
        if (Cache::has($dedupKey)) {
            return;
        }
        Cache::put($dedupKey, true, 60);

        $notification = $event->notification;

        // Map notification class → alert type + subtype
        $alertType = match (true) {
            $notification instanceof EventReminderNotification,
            $notification instanceof BureaucracyDeadlineNotification,
            $notification instanceof MarketClosureNotification => AlertType::Reminder,
            default => AlertType::System,
        };

        $subtype = match (true) {
            $notification instanceof TransitDisruptionNotification => 'transit_disruption',
            $notification instanceof TransitDelayNotification => 'transit_delay',
            $notification instanceof WeatherAlertNotification => 'weather',
            $notification instanceof RhineFloodNotification => 'rhine',
            $notification instanceof BuergeramtSlotNotification => 'buergeramt',
            $notification instanceof BureaucracyDeadlineNotification => 'bureaucracy_deadline',
            $notification instanceof EventReminderNotification => 'event_reminder',
            $notification instanceof MarketClosureNotification => 'market_closure',
            default => 'generic',
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
            'subtype' => $subtype,
            'title' => $title,
            'body' => $data['body'] ?? $data['summary'] ?? '',
            'deep_link' => $data['url'] ?? null,
        ]);
    }
}
