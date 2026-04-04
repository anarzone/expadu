<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class RhineFloodNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly float $levelCm,
        private readonly string $status,
        private readonly string $trend,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        $body = "Rhine at {$this->levelCm}cm ({$this->trend}). Status: {$this->status}.";

        return (new WebPushMessage)
            ->title('Rhine Water Level Alert')
            ->icon('/favicon.svg')
            ->body($body)
            ->action('View level', 'view_rhine')
            ->options(['TTL' => 3600])
            ->data(['url' => '/dashboard']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'rhine_flood',
            'title' => "Rhine water level {$this->status}",
            'body' => "Water level at {$this->levelCm}cm and {$this->trend}.",
            'url' => null,
        ];
    }
}
