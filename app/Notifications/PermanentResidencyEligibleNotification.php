<?php

namespace App\Notifications;

use App\ContextEngine\ClaimsReservedPush;
use App\ContextEngine\ReservedPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * The one positive ContextEngine notification: the resident has held their
 * permit past the Niederlassungserlaubnis threshold, so they may now apply for
 * permanent residency. Shared by the push dispatcher and the alert-center
 * recorder (which reads toArray() for the Good-news card) — one formatter, two
 * surfaces, no drift. The track note carries the conditions date math cannot
 * prove, framed as "to check" rather than "you qualify".
 */
class PermanentResidencyEligibleNotification extends Notification implements ReservedPush, ShouldQueue
{
    use ClaimsReservedPush, Queueable;

    public function __construct(
        public int $monthsHeld,
        public int $thresholdMonths,
        public string $trackNote,
        public ?array $pushReservation = null, // PushLedger reservation for the milestone's push
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    /** Prevent already queued legacy estimates from being delivered. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return false;
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->icon('/icons/buergeramt.png')
            ->body($this->body())
            ->action('See requirements', 'view_bureaucracy')
            ->options(['TTL' => 3600])
            ->data(['url' => $this->url()]);
    }

    /**
     * @return array{type: string, title: string, body: string, months_held: int, threshold_months: int, url: string}
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'permanent_residency_eligible',
            'title' => $this->title(),
            'body' => $this->body(),
            'months_held' => $this->monthsHeld,
            'threshold_months' => $this->thresholdMonths,
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return '/bureaucracy';
    }

    private function title(): string
    {
        return 'You may now qualify for permanent residency';
    }

    private function body(): string
    {
        return "You've held your permit {$this->monthsHeld} months — past the {$this->thresholdMonths}-month mark. {$this->trackNote} Worth checking the remaining conditions when you're ready.";
    }
}
