<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class LeaveByReminderNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{place_name: string, leave_by: string, mode: string, mode_emoji: string, weather_context: string, arrive_by: string, travel_min: int}  $data
     */
    public function __construct(
        public readonly array $data,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("Leave by {$this->data['leave_by']} for {$this->data['place_name']}")
            ->icon('/favicon.svg')
            ->body("{$this->data['mode_emoji']} {$this->data['mode']} · {$this->data['travel_min']} min · {$this->data['weather_context']}")
            ->action('View route', 'view_transit')
            ->options(['TTL' => 1800])
            ->data(['url' => '/transit']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'leave_by_reminder',
            'title' => "Leave by {$this->data['leave_by']} for {$this->data['place_name']}",
            'body' => "{$this->data['mode_emoji']} {$this->data['mode']} · {$this->data['travel_min']} min · {$this->data['weather_context']}",
            'url' => '/transit',
        ];
    }
}
