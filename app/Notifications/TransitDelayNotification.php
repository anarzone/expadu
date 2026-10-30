<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TransitDelayNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Queued per user on the commute queue, so an evaluator looping over
     * users never waits on a push service.
     */
    public function __construct(
        private readonly string $line,
        private readonly int $delayMinutes,
        private readonly string $stopName,
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
            ->title("{$this->line} running {$this->delayMinutes} min late")
            ->icon('/favicon.svg')
            ->body("Delay at {$this->stopName}. Consider alternative routes.")
            ->action('Check live status', 'view_transit')
            ->options(['TTL' => 1800])
            ->data(['url' => '/travel']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'transit_delay',
            'title' => "{$this->line} running {$this->delayMinutes} minutes late",
            'body' => "Delay at {$this->stopName}. Consider alternative routes.",
            'url' => '/travel',
        ];
    }
}
