<?php

namespace App\Http\Controllers\Api;

use App\Composer\TasteSignals;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartTripRequest;
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
    public function start(StartTripRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if ($request->resumableTrip() !== null) {
            return response()->json(['trip' => $request->resumableTrip()->toShared()]);
        }

        // Never replace a running trip by surprise (another tab, a stale page):
        // the person confirms "Replace the trip you're on?" first.
        $running = $request->replacesTrip();
        if ($running !== null && ! $request->boolean('replace')) {
            return response()->json([
                'code' => 'trip_in_progress',
                'message' => 'You’re on a trip to '.($running->destination['name'] ?? 'somewhere').'. Replace it?',
                'trip' => [
                    'destination' => $running->destination['name'] ?? null,
                    'arrive_time' => $running->journey['arrive_time'] ?? null,
                ],
            ], 409);
        }

        // Persist the WHOLE journey — validating journey.legs makes Laravel
        // return only that sub-key, but the live timeline needs every field
        // (times, colours, intermediate-stop coordinates for GPS matching).
        $trip = ActiveTrip::updateOrCreate(
            ['user_id' => $request->user()->id],
            [
                'journey' => $request->input('journey'),
                'destination' => $validated['destination'],
                'origin' => $validated['origin'] ?? null,
                'target' => $request->target(),
                'started_at' => now(),
            ],
        );

        $this->scheduleGetOffReminders($trip, $request->user());
        app(TasteSignals::class)->tripStarted(
            $request->user(),
            (string) $validated['destination']['name'],
            (float) $validated['destination']['lat'],
            (float) $validated['destination']['lng'],
        );

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
