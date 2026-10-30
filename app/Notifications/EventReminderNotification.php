<?php

namespace App\Notifications;

use App\Home\WhatsOn;
use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class EventReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Event $event,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        $time = $this->event->starts_at?->format('H:i') ?? '';

        return (new WebPushMessage)
            ->title("Tomorrow: {$this->event->title}")
            ->icon('/favicon.svg')
            ->body("{$this->event->venue} at {$time}")
            ->action('View event', 'view_event')
            ->options(['TTL' => 7200])
            ->data(['url' => $this->link()]);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        $time = $this->event->starts_at?->format('H:i') ?? '';
        $attendees = $this->event->attendees_count ?? 0;

        return [
            'type' => 'event_reminder',
            'title' => "Tomorrow: {$this->event->title}",
            'body' => "You're going to {$this->event->title} at {$this->event->venue}. Starts at {$time}.".($attendees > 0 ? " {$attendees} people attending." : ''),
            'url' => $this->link(),
        ];
    }

    /** The event's details in What's on, on its day; the day's listings when it has no start. */
    private function link(): string
    {
        return $this->event->starts_at === null ? WhatsOn::url() : WhatsOn::eventUrl($this->event->id, $this->event->starts_at->toImmutable());
    }
}
