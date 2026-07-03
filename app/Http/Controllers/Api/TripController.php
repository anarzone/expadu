<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendTripStopReminder;
use App\Models\ActiveTrip;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The user's live trip session. Starting persists the chosen journey so it
 * survives an app close and shows across every screen (the app-wide banner)
 * until ended; ending clears it. One active trip per user.
 */
class TripController extends Controller
{
    /** Begin (or replace) the active trip with the chosen journey. */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'journey' => ['required', 'array'],
            'journey.legs' => ['required', 'array', 'min:1'],
            'destination' => ['required', 'array'],
            'destination.name' => ['required', 'string', 'max:200'],
            'destination.lat' => ['required', 'numeric', 'between:-90,90'],
            'destination.lng' => ['required', 'numeric', 'between:-180,180'],
            'destination.emoji' => ['nullable', 'string', 'max:16'],
            'origin' => ['nullable', 'array'],
            'origin.name' => ['nullable', 'string', 'max:200'],
            'origin.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'origin.lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Persist the WHOLE journey — validating journey.legs makes Laravel
        // return only that sub-key, but the live timeline needs every field
        // (times, colours, intermediate-stop coordinates for GPS matching).
        $trip = ActiveTrip::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'journey' => $request->input('journey'),
                'destination' => $validated['destination'],
                'origin' => $validated['origin'] ?? null,
                'started_at' => now(),
            ],
        );

        $this->scheduleGetOffReminders($trip, $request->user());

        return response()->json(['trip' => $trip->toShared()]);
    }

    /**
     * Lead time before a stop's scheduled arrival to nudge the traveller —
     * roughly one stop early, so there's time to reach the doors.
     */
    private const REMINDER_LEAD_SECONDS = 120;

    /**
     * Queue a "get off" web push for each transit exit (changes + the final
     * stop), timed from the journey's schedule so it lands even with the app
     * closed. Jobs self-validate against the live trip at run time, so a later
     * end/switch simply makes the stale ones no-op — no cancellation needed.
     */
    private function scheduleGetOffReminders(ActiveTrip $trip, User $user): void
    {
        // No point queuing anything the user can't or won't receive.
        if (
            ! $user->wantsNotification('transit')
            || ! $user->pushSubscriptions()->exists()
        ) {
            return;
        }

        $legs = $trip->journey['legs'] ?? [];

        $transitIndexes = [];

        foreach ($legs as $i => $leg) {
            $mode = $leg['mode'] ?? '';

            if ($mode !== 'walk' && $mode !== 'bike') {
                $transitIndexes[] = $i;
            }
        }

        if ($transitIndexes === []) {
            return;
        }

        $lastTransit = end($transitIndexes);
        $startedAt = $trip->started_at->toIso8601String();

        foreach ($transitIndexes as $i) {
            $arriveAt = $legs[$i]['arrive_at'] ?? null;
            $stopName = $legs[$i]['to']['name'] ?? null;

            if ($arriveAt === null || $stopName === null) {
                continue;
            }

            $fireAt = CarbonImmutable::parse($arriveAt)
                ->subSeconds(self::REMINDER_LEAD_SECONDS);

            // A stop already at/behind us can't be reminded about.
            if (! $fireAt->isFuture()) {
                continue;
            }

            SendTripStopReminder::dispatch(
                $user->id,
                $startedAt,
                $stopName,
                $i === $lastTransit,
            )->delay($fireAt);
        }
    }

    /** End the active trip (arrived, or abandoned). */
    public function end(Request $request): JsonResponse
    {
        ActiveTrip::query()->where('user_id', $request->user()->id)->delete();

        return response()->json(['ended' => true]);
    }
}
