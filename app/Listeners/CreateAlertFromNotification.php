<?php

namespace App\Listeners;

use App\Alerts\AlertClassifier;
use App\Models\Alert;
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
 * Creates an Alert row from a sent 'database'-channel notification — the path
 * for direct notifications like event reminders.
 *
 * ContextEngine alerts (transit / weather / rhine / market / bureaucracy) are
 * NOT created here: RecordContextAlert owns them off the alert_page channel so
 * they land in the center even when push is muted/throttled. They're skipped
 * below to avoid a double write.
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

        $notification = $event->notification;

        // ContextEngine notifications are recorded off the alert_page channel by
        // RecordContextAlert (push-independent + lane/category/severity-tagged).
        // Skip them here so the center isn't written twice.
        if ($notification instanceof TransitDisruptionNotification
            || $notification instanceof TransitDelayNotification
            || $notification instanceof WeatherAlertNotification
            || $notification instanceof RhineFloodNotification
            || $notification instanceof MarketClosureNotification
            || $notification instanceof BureaucracyDeadlineNotification) {
            return;
        }

        // Dedup: hash notification class + user + title to prevent double-firing
        $data = $notification->toArray($event->notifiable);
        $dedupKey = 'alert_created:'.md5(
            get_class($notification).':'.$event->notifiable->id.':'.($data['title'] ?? '')
        );
        if (Cache::has($dedupKey)) {
            return;
        }
        Cache::put($dedupKey, true, 60);

        $subtype = $notification instanceof EventReminderNotification ? 'event_reminder' : 'generic';
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

        // Direct notifications are informational (event reminders).
        $severity = AlertClassifier::severity('info');

        Alert::create([
            'user_id' => $userId,
            'type' => AlertClassifier::alertType($subtype),
            'subtype' => $subtype,
            'severity' => $severity,
            'category' => AlertClassifier::category($subtype),
            'lane' => AlertClassifier::lane($subtype, $severity),
            'title' => $title,
            'body' => $data['body'] ?? $data['summary'] ?? '',
            'deep_link' => $data['url'] ?? null,
        ]);
    }
}
