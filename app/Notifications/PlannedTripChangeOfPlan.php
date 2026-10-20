<?php

namespace App\Notifications;

use App\Models\PlannedTrip;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Change of plan" (Plan a trip's "We keep watching it"): the planned way
 * was cancelled, runs late into the margin, has a new KVB notice, or rain
 * is forecast on its walk; with the way to take instead when there is one. It opens Plan a trip for that
 * plan, by the plan's id only.
 */
class PlannedTripChangeOfPlan extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $change  PlannedTripWatch::check()
     */
    public function __construct(
        private readonly PlannedTrip $trip,
        private readonly array $change,
    ) {}

    /** @return array<int, string> */
    public function via(mixed $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->icon('/favicon.svg')
            ->body($this->body())
            ->data(['url' => $this->url()])
            ->options(['TTL' => 900]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return [
            'type' => 'planned_trip_change',
            'kind' => $this->change['kind'],
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => $this->url(),
        ];
    }

    private function title(): string
    {
        return "Change of plan · {$this->trip->title}";
    }

    /**
     * "Your 13:14 tram 4 is cancelled. Instead: leave 13:20 for tram 4 at
     * 13:24, there 13:58."
     */
    public function body(): string
    {
        $change = $this->change;
        $ride = "{$change['ride']['time']} ".mb_strtolower(PlannedTrip::rideName($change['ride']));
        $what = match ($change['kind']) {
            'cancelled' => "Your {$ride} is cancelled.",
            'late' => "Your {$ride} runs {$change['late_min']} min late: there {$change['arrive_time']}, "
                .($change['spare_min'] >= 0 ? "{$change['spare_min']} min before {$change['start_time']}." : "after {$change['start_time']}."),
            default => 'KVB notice for '.mb_strtolower(PlannedTrip::rideName($change['ride']))
                .": {$change['notice']}",
        };

        $instead = $change['instead'] ?? null;
        if ($change['kind'] === 'weather' && $instead !== null) {
            $by = $instead['ride'] === null ? '' : ' for '.mb_strtolower(PlannedTrip::rideName($instead['ride']))." at {$instead['ride']['time']}";

            return "Rain on your {$change['walk_min']}-min walk · this way walks {$instead['walk_min']}: leave {$instead['leave_time']}{$by}, there {$instead['arrive_time']}.";
        }
        if ($instead === null) {
            return $change['kind'] === 'cancelled' ? "{$what} Plan a trip has the other ways." : $what;
        }
        if ($instead['status'] === 'late') {
            return "{$what} Nothing else gets you there by {$change['start_time']}: the earliest is there {$instead['arrive_time']}.";
        }
        $by = $instead['ride'] === null ? '' : ' for '.mb_strtolower(PlannedTrip::rideName($instead['ride']))." at {$instead['ride']['time']}";

        $tight = $instead['status'] === 'tight' ? ", only {$instead['spare_min']} min before {$change['start_time']}" : '';

        return "{$what} Instead: leave {$instead['leave_time']}{$by}, there {$instead['arrive_time']}{$tight}.";
    }

    private function url(): string
    {
        return '/timetable?'.http_build_query(['view' => 'journey', 'activity' => $this->trip->target_id]);
    }
}
