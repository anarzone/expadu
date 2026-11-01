<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class WeatherAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Queued per user on the commute queue, so an evaluator looping over
     * users never waits on a push service.
     */
    public function __construct(
        private readonly string $summary,
        private readonly string $detail,
    ) {
        $this->onQueue('commute');
    }

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
            ->data(['url' => '/travel']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'weather_alert',
            'title' => $this->summary,
            'body' => $this->detail,
            'url' => null,
        ];
    }
}
