<?php

namespace App\Console\Commands\Travel;

use App\ContextEngine\ActionBus;
use App\ContextEngine\DayImportance;
use App\ContextEngine\ScoredAction;
use App\Day\PlannedTripWatch;
use App\Models\PlannedTrip;
use App\Notifications\PlannedTripChangeOfPlan;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "We keep watching it": every two minutes, the planned ways leaving within
 * the next 90 minutes are checked against live data (PlannedTripWatch).
 * A change of plan is pushed once per severity (a notice, then late, then
 * cancelled; never a milder one after a worse), claimed before it's sent,
 * and only to someone who wants transit notifications. A trip that fails is
 * reported and the others are still watched; a push that fails gives its
 * claim back, so the next run tries again.
 */
class WatchPlannedTrips extends Command
{
    /** Each change, as severe as it is for the way (PlannedTrip::ALERTS, mildest first). */
    private const SEVERITY = ['weather' => 'minor', 'notice' => 'moderate', 'late' => 'major', 'cancelled' => 'critical'];

    protected $signature = 'travel:watch-planned-trips';

    protected $description = 'Push a "Change of plan" when a planned way is cancelled, runs late into its margin or gets a KVB notice';

    public function handle(PlannedTripWatch $watch): int
    {
        // In UTC, as the timestamps are compared in the database.
        $now = CarbonImmutable::now('UTC');
        $sent = 0;
        $failed = 0;

        PlannedTrip::query()
            ->with('user')
            ->where('depart_at', '>', $now)
            ->where('depart_at', '<=', $now->addMinutes(PlannedTrip::WATCH_FROM_MINUTES))
            ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhere('alerted_kind', '!=', 'cancelled'))
            ->each(function (PlannedTrip $trip) use ($watch, $now, &$sent, &$failed): void {
                // As Travel's get-off reminders: nothing for someone who turned transit off.
                if ($trip->user === null || ! $trip->user->wantsNotification('transit')) {
                    return;
                }
                try {
                    $sent += $this->watch($watch, $trip, $now) ? 1 : 0;
                } catch (Throwable $e) {
                    $failed++;
                    report($e);
                }
            });

        $this->info("Sent {$sent} change(s) of plan.");

        if ($sent > 0 || $failed > 0) {
            Log::info('Changes of plan sent', ['sent' => $sent, 'failed' => $failed]);
        }

        return self::SUCCESS;
    }

    /** Whether a change of plan was sent for this trip. */
    private function watch(PlannedTripWatch $watch, PlannedTrip $trip, CarbonImmutable $now): bool
    {
        $change = $watch->check($trip, $now);
        if ($change === null) {
            return false;
        }
        $milder = array_slice(PlannedTrip::ALERTS, 0, (int) array_search($change['kind'], PlannedTrip::ALERTS, true));
        $claimed = PlannedTrip::query()
            ->whereKey($trip->id)
            ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhereIn('alerted_kind', $milder))
            ->update(['alerted_kind' => $change['kind'], 'alerted_at' => $now]);
        if ($claimed !== 1) {
            return false;
        }
        // A change of plan is a risk to the day (DayImportance).
        DayImportance::forget($trip->user_id, $trip->date);

        $back = fn () => PlannedTrip::query()->whereKey($trip->id)->update([
            'alerted_kind' => $trip->getRawOriginal('alerted_kind'),
            'alerted_at' => $trip->getRawOriginal('alerted_at'),
        ]);
        try {
            // Through the one push gate, on time: it's about the way they're taking. Worse is a
            // higher severity, so once per situation pushes again only on a worse change.
            $decision = app(ActionBus::class)->deliver($trip->user,
                ScoredAction::claimed('planned_trip_change', "planned_trip_change:{$trip->id}", self::SEVERITY[$change['kind']] ?? 'moderate', $trip->depart_at, ['trip' => $trip->id]),
                new PlannedTripChangeOfPlan($trip, $change), $trip->depart_at);
        } catch (Throwable $e) {
            // Not sent: the trip goes back to what it was told before.
            $back();

            throw $e;
        }
        // Held (quiet hours, for a way more than two hours off): told again on a later run.
        if ($decision->later) {
            $back();
        }

        return $decision->send;
    }
}
