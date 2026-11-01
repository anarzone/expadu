<?php

namespace App\Console\Commands\Travel;

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\PlannedTrip;
use App\Notifications\PlannedTripLeaveReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Remind me when to leave": once a minute, the planned ways leaving within
 * the next five minutes whose reminder is on and not sent yet. Each is
 * claimed before it's sent, so two runs never send it twice; one that fails
 * to send gives its claim back and is reported, and the next run (a minute
 * on, still before the way leaves) tries it again. It goes through the one
 * push gate (ActionBus::deliver): on time, as they asked for it, but counted
 * in the day's budget; one held gives its claim back too.
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
        $failed = 0;

        PlannedTrip::query()
            ->with('user')
            ->where('remind', true)
            ->whereNull('reminded_at')
            ->where('depart_at', '>', $now)
            ->where('depart_at', '<=', $now->copy()->addMinutes(PlannedTrip::REMIND_BEFORE_MINUTES))
            ->each(function (PlannedTrip $trip) use ($now, &$sent, &$failed): void {
                $claimed = PlannedTrip::query()->whereKey($trip->id)->whereNull('reminded_at')->update(['reminded_at' => $now]);
                if ($claimed !== 1 || $trip->user === null) {
                    return;
                }
                try {
                    $decision = app(ActionBus::class)->deliver($trip->user,
                        ScoredAction::claimed('planned_trip_leave', "planned_trip_leave:{$trip->id}", 'moderate', $trip->depart_at, ['trip' => $trip->id]),
                        new PlannedTripLeaveReminder($trip), $trip->depart_at);
                    if ($decision->later) {
                        PlannedTrip::query()->whereKey($trip->id)->update(['reminded_at' => null]);
                    }
                    $sent += $decision->send ? 1 : 0;
                } catch (Throwable $e) {
                    // Not sent, so not marked sent.
                    PlannedTrip::query()->whereKey($trip->id)->update(['reminded_at' => null]);
                    $failed++;
                    report($e);
                }
            });

        $this->info("Sent {$sent} leave reminder(s).");

        if ($sent > 0 || $failed > 0) {
            Log::info('Leave reminders sent', ['sent' => $sent, 'failed' => $failed]);
        }

        return self::SUCCESS;
    }
}
