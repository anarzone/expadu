<?php

namespace App\Console\Commands\Travel;

use App\Models\PlannedTrip;
use App\Notifications\PlannedTripLeaveReminder;
use Illuminate\Console\Command;

/**
 * "Remind me when to leave": once a minute, the planned ways leaving within
 * the next five minutes whose reminder is on and not sent yet. Each is
 * claimed before it's sent, so two runs never send it twice.
 */
class SendLeaveReminders extends Command
{
    protected $signature = 'travel:send-leave-reminders';

    protected $description = 'Push "time to leave" for planned ways with their reminder on';

    public function handle(): int
    {
        // In UTC, as the timestamps are compared in the database.
        $now = now()->utc();
        $sent = 0;

        PlannedTrip::query()
            ->with('user')
            ->where('remind', true)
            ->whereNull('reminded_at')
            ->where('depart_at', '>', $now)
            ->where('depart_at', '<=', $now->copy()->addMinutes(PlannedTrip::REMIND_BEFORE_MINUTES))
            ->each(function (PlannedTrip $trip) use ($now, &$sent): void {
                $claimed = PlannedTrip::query()->whereKey($trip->id)->whereNull('reminded_at')->update(['reminded_at' => $now]);
                if ($claimed === 1 && $trip->user !== null) {
                    $trip->user->notify(new PlannedTripLeaveReminder($trip));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} leave reminder(s).");

        return self::SUCCESS;
    }
}
