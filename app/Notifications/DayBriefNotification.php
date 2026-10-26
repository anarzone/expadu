<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The day's brief (DayBriefs): the morning one, or the evening before an important day. Its lines
 * are the brief's own (BriefText), in the notification centre and as one push.
 */
class DayBriefNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $lines */
    public function __construct(
        private readonly string $title,
        private readonly array $lines,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/favicon.svg')
            ->body(implode("\n", $this->lines))
            ->data(['url' => '/'])
            ->options(['TTL' => 3600]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'day_brief',
            'title' => $this->title,
            'body' => implode("\n", $this->lines),
            'url' => '/',
        ];
    }
}
