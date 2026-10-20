<?php

namespace App\Console\Commands\Travel;

use App\Day\PlannedTripWatch;
use App\Models\PlannedTrip;
use App\Notifications\PlannedTripChangeOfPlan;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * "We keep watching it": every two minutes, the planned ways leaving within
 * the next 90 minutes are checked against live data (PlannedTripWatch).
 * A change of plan is pushed once per severity (a notice, then late, then
 * cancelled; never a milder one after a worse), claimed before it's sent.
 */
class WatchPlannedTrips extends Command
{
    protected $signature = 'travel:watch-planned-trips';

    protected $description = 'Push a "Change of plan" when a planned way is cancelled, runs late into its margin or gets a KVB notice';

    public function handle(PlannedTripWatch $watch): int
    {
        // In UTC, as the timestamps are compared in the database.
        $now = CarbonImmutable::now('UTC');
        $sent = 0;

        PlannedTrip::query()
            ->with('user')
            ->where('depart_at', '>', $now)
            ->where('depart_at', '<=', $now->addMinutes(PlannedTrip::WATCH_FROM_MINUTES))
            ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhere('alerted_kind', '!=', 'cancelled'))
            ->each(function (PlannedTrip $trip) use ($watch, $now, &$sent): void {
                if ($trip->user === null) {
                    return;
                }
                $change = $watch->check($trip, $now);
                if ($change === null) {
                    return;
                }
                $milder = array_slice(PlannedTrip::ALERTS, 0, (int) array_search($change['kind'], PlannedTrip::ALERTS, true));
                $claimed = PlannedTrip::query()
                    ->whereKey($trip->id)
                    ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhereIn('alerted_kind', $milder))
                    ->update(['alerted_kind' => $change['kind'], 'alerted_at' => $now]);
                if ($claimed === 1) {
                    $trip->user->notify(new PlannedTripChangeOfPlan($trip, $change));
                    $sent++;
                }
            });

        $this->info("Sent {$sent} change(s) of plan.");

        return self::SUCCESS;
    }
}
