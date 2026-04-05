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

        // Map notification class → alert type + subtype
        $alertType = match (true) {
            $notification instanceof EventReminderNotification => AlertType::Reminder,
            $notification instanceof BuergeramtSlotNotification,
            $notification instanceof TransitDisruptionNotification,
            $notification instanceof TransitDelayNotification,
            $notification instanceof RhineFloodNotification,
            $notification instanceof WeatherAlertNotification => AlertType::System,
            $notification instanceof GenericAlertNotification => AlertType::tryFrom($data['alert_type'] ?? 'system') ?? AlertType::System,
            default => AlertType::System,
        };

        $subtype = match (true) {
            $notification instanceof TransitDisruptionNotification => 'transit_disruption',
            $notification instanceof TransitDelayNotification => 'transit_delay',
            $notification instanceof WeatherAlertNotification => 'weather',
            $notification instanceof RhineFloodNotification => 'rhine',
            $notification instanceof BuergeramtSlotNotification => 'buergeramt',
            $notification instanceof EventReminderNotification => 'event_reminder',
            $notification instanceof GenericAlertNotification => $this->detectGenericSubtype($data),
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

    /** Detect granular subtype for GenericAlertNotification based on content */
    private function detectGenericSubtype(array $data): string
    {
        $title = strtolower($data['title'] ?? '');
        $url = $data['url'] ?? '';

        // Social subtypes
        if (str_contains($title, 'partner') || str_contains($title, 'match')) {
            return 'partner_match';
        }
        if (str_contains($title, 'connection') || str_contains($title, 'accepted')) {
            return 'connection_accepted';
        }
        if (str_contains($title, 'joined') || str_contains($title, 'also')) {
            return 'event_activity';
        }
        if (str_contains($title, 'tip') || str_contains($title, 'area')) {
            return 'area_tip';
        }

        // Reminder subtypes
        if (str_contains($title, 'tomorrow')) {
            return 'event_reminder';
        }
        if (str_contains($title, 'bank') || str_contains($title, 'steuer')) {
            return 'bureaucracy_reminder';
        }
        if (str_contains($title, 'reminder')) {
            return 'reminder';
        }

        // Fallback by alert_type
        return match ($data['alert_type'] ?? 'system') {
            'social' => 'social_other',
            'reminder' => 'reminder_other',
            default => 'generic',
        };
    }
}
