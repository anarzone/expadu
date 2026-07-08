<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TransitDisruptionNotification extends Notification
{
    use Queueable;

    /**
     * @param  array{line: string, summary: string}  $disruption
     */
    public function __construct(
        public array $disruption,
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
            ->title("Disruption on {$this->disruption['line']}")
            ->icon('/icons/transit-alert.png')
            ->body($this->disruption['summary'])
            ->action('View details', 'view_transit')
            ->options(['TTL' => 3600])
            ->data(['url' => '/timetable']);
    }

    /**
     * @return array{type: string, line: string, summary: string, url: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'transit_disruption',
            'title' => "Disruption on {$this->disruption['line']}",
            'body' => $this->disruption['summary'],
            'line' => $this->disruption['line'],
            'summary' => $this->disruption['summary'],
            'url' => '/timetable',
        ];
    }
}
