<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class GenericAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $deepLink = '/alerts',
        public string $alertType = 'system',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon('/favicon.svg')
            ->body($this->body)
            ->action('View', 'view_alert')
            ->options(['TTL' => 7200])
            ->data(['url' => $this->deepLink]);
    }

    /**
     * @return array{type: string, title: string, body: string, url: string, alert_type: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'generic_alert',
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->deepLink,
            'alert_type' => $this->alertType,
        ];
    }
}
