<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A time-sensitive nudge to leave the vehicle at the right stop — a change or
 * the final exit. Web-push only (no alert-center record): it's a transient
 * heads-up for the moment, not something to keep. Scheduled by
 * SendTripStopReminder from the trip's timetable so it fires even with the app
 * closed, where live GPS can't run.
 */
class GetOffReminderNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $stopName,
        private readonly bool $isFinal,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/favicon.svg')
            // Short TTL — a get-off nudge is worthless once the stop has passed.
            ->options(['TTL' => 300])
            // One tag so a fresh reminder replaces a stale one on screen.
            ->tag('trip-get-off')
            ->data(['url' => '/timetable']);
    }

    private function title(): string
    {
        return $this->isFinal
            ? "Get off next · {$this->stopName}"
            : "Change soon · {$this->stopName}";
    }

    private function body(): string
    {
        return $this->isFinal
            ? 'Your stop is coming up — get ready to exit.'
            : "Get off at {$this->stopName} for your connection.";
    }
}
