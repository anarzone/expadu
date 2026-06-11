<?php

namespace App\Notifications;

use App\Models\EventReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The per-occurrence "Remind me" delivery — in-app centre + push,
 * timed by the user's chosen offset.
 */
class EventOccurrenceReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly EventReminder $reminder,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        $event = $this->reminder->event;
        $time = $this->reminder->occurrence_start->timezone('Europe/Berlin')->format('H:i');

        return (new WebPushMessage)
            ->title($event->title_en ?: $event->title)
            ->icon('/favicon.svg')
            ->body(($event->location_name ? "{$event->location_name} · " : '')."starts {$time}")
            ->action('View events', 'view_events')
            ->data(['url' => '/events'])
            ->options(['TTL' => 7200]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        $event = $this->reminder->event;
        $time = $this->reminder->occurrence_start->timezone('Europe/Berlin')->format('H:i');

        return [
            'type' => 'event_reminder',
            'title' => $event->title_en ?: $event->title,
            'body' => ($event->location_name ? "At {$event->location_name}. " : '')."Starts at {$time}.",
            'url' => '/events',
        ];
    }
}
