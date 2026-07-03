<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\GetOffReminderNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fires a "get off" web push at a scheduled stop time. Dispatched with a delay
 * when a trip starts, so it lands even with the app closed (where live GPS
 * can't run). Self-validating: it re-reads the user's active trip at run time
 * and quietly does nothing if the trip was ended or swapped for another —
 * cheaper and safer than trying to cancel already-queued jobs.
 */
class SendTripStopReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        // The started_at of the trip this reminder belongs to. If the live trip
        // no longer matches, it was ended or replaced — skip.
        public readonly string $tripStartedAt,
        public readonly string $stopName,
        public readonly bool $isFinal,
    ) {
        // Prod's worker only consumes redis (queues commute, default); a delayed
        // job on any other connection would never run.
        $this->onConnection('redis')->onQueue('commute');
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        $trip = $user->activeTrip;

        // No live trip, or a different one now (ended / switched / re-planned).
        if (
            $trip === null
            || $trip->started_at?->toIso8601String() !== $this->tripStartedAt
        ) {
            return;
        }

        if (! $user->wantsNotification('transit')) {
            return;
        }

        $user->notify(
            new GetOffReminderNotification($this->stopName, $this->isFinal),
        );
    }
}
