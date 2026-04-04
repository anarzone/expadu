<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class WeatherAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $summary,
        private readonly string $detail,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->summary)
            ->icon('/favicon.svg')
            ->body($this->detail)
            ->action('View forecast', 'view_weather')
            ->options(['TTL' => 3600])
            ->data(['url' => '/transit']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'weather_alert',
            'title' => $this->summary,
            'body' => $this->detail,
            'url' => '/transit',
        ];
    }
}
