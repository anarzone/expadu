<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class MarketClosureNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Queued per user on the commute queue, so an evaluator looping over
     * users never waits on a push service.
     */
    public function __construct(
        private readonly string $reason,
        private readonly ?string $holidayName = null,
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
        $title = $this->holidayName
            ? "Shops closed tomorrow — {$this->holidayName}"
            : 'Shops closed tomorrow — Sunday';

        return (new WebPushMessage)
            ->title($title)
            ->icon('/favicon.svg')
            ->body('Most supermarkets and shops will be closed. Plan your shopping for today.')
            ->action('Got it', 'dismiss')
            ->options(['TTL' => 7200])
            ->data(['url' => '/today']);
    }

    /** @return array<string, mixed> */
    public function toArray(mixed $notifiable): array
    {
        $title = $this->holidayName
            ? "Shops closed tomorrow — {$this->holidayName}"
            : 'Shops closed tomorrow — Sunday';

        return [
            'type' => 'market_closure',
            'title' => $title,
            'body' => 'Most supermarkets and shops will be closed. Plan your shopping for today.',
            'url' => null,
        ];
    }
}
