<?php

namespace App\Notifications;

use App\Models\PlannedTrip;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Remind me when to leave" (Plan a trip's saved way): five minutes before
 * the way leaves, in the notification centre and as a push. Only ever sent
 * because the person switched it on for this way.
 */
class PlannedTripLeaveReminder extends Notification
{
    use Queueable;

    public function __construct(
        private readonly PlannedTrip $trip,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title("Time to leave for {$this->trip->title}")
            ->icon('/favicon.svg')
            ->body($this->line())
            ->data(['url' => '/travel'])
            ->options(['TTL' => 600]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'planned_trip_leave',
            'title' => "Time to leave for {$this->trip->title}",
            'body' => $this->line(),
            'url' => '/travel',
        ];
    }

    /** "Leave at 13:10 · Tram 4 at 13:14" */
    private function line(): string
    {
        $shared = $this->trip->toShared();
        $ride = $shared['ride'];

        return "Leave at {$shared['depart_time']}".($ride !== null ? ' · '.PlannedTrip::rideName($ride)." at {$ride['time']}" : '');
    }
}
