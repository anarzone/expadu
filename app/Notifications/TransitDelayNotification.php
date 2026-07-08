<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TransitDelayNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $line,
        private readonly int $delayMinutes,
        private readonly string $stopName,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("{$this->line} running {$this->delayMinutes} min late")
            ->icon('/favicon.svg')
            ->body("Delay at {$this->stopName}. Consider alternative routes.")
            ->action('Check live status', 'view_transit')
            ->options(['TTL' => 1800])
            ->data(['url' => '/timetable']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'transit_delay',
            'title' => "{$this->line} running {$this->delayMinutes} minutes late",
            'body' => "Delay at {$this->stopName}. Consider alternative routes.",
            'url' => '/timetable',
        ];
    }
}
