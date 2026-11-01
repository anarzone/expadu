<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * What threatens a stop of their plan starting within two hours (PlanRisks): closed that day,
 * weather while they're outdoors, a disruption or delay near it. One for the stop, the worst first,
 * in the notification centre and as a push, in the risks' own words.
 */
class PlanStopRiskNotification extends Notification
{
    use Queueable;

    /** @param  list<string>  $more  the stop's other risks, in their own words */
    public function __construct(
        private readonly string $title,
        private readonly string $start,
        private readonly array $more = [],
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
            ->body($this->body())
            ->data(['url' => '/'])
            ->options(['TTL' => 1800]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'plan_risk',
            'title' => $this->title,
            'body' => $this->body(),
            'url' => '/',
        ];
    }

    private function body(): string
    {
        return implode(' ', [...$this->more, "In your plan at {$this->start}."]);
    }
}
