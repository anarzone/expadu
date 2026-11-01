<?php

namespace App\Day;

use App\Models\PlannedTrip;
use App\Models\User;
use App\Transit\DepartureTime;
use Carbon\CarbonInterface;

/**
 * "Add to plan": the way picked for one of the person's plans on a day, with its "Remind me
 * when to leave". Plan a trip's Add to plan and Composer's plan_trip_for_later both keep it here.
 */
final class PlannedTrips
{
    /** A kept way as Undo puts it back: whether it reminded or alerted already too, so it doesn't again. */
    public const KEPT = ['title', 'journey', 'depart_at', 'arrive_at', 'remind', 'reminded_at', 'planned_at', 'alerted_kind', 'alerted_at'];

    /**
     * Keeps the way for this plan, replacing any it had: a new way is a new reminder, and watched afresh.
     *
     * @param  array<string, mixed>  $journey  a journey as StoredJourney checks it
     */
    public function keep(User $user, string $date, DayItem $plan, array $journey, bool $remind): PlannedTrip
    {
        return PlannedTrip::query()->updateOrCreate(
            ['user_id' => $user->id, 'date' => $date, 'target_id' => $plan->id],
            [
                'title' => mb_substr($plan->title, 0, 200),
                'journey' => $journey,
                'depart_at' => DepartureTime::parse($journey['depart_at']),
                'arrive_at' => DepartureTime::parse($journey['arrive_at']),
                'remind' => $remind,
                'planned_at' => now(),
                'reminded_at' => null,
                'alerted_kind' => null,
                'alerted_at' => null,
            ],
        );
    }

    /**
     * A kept way as it is now, for Undo: its fields, times as ISO strings.
     *
     * @return array<string, mixed>
     */
    public function snapshot(PlannedTrip $trip): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof CarbonInterface ? $value->toIso8601String() : $value, $trip->only(self::KEPT));
    }

    /**
     * Puts back a way exactly as it was (snapshot), reminder and alerts included.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function restore(User $user, string $date, string $planId, array $snapshot): PlannedTrip
    {
        return PlannedTrip::query()->updateOrCreate(['user_id' => $user->id, 'date' => $date, 'target_id' => $planId],
            array_intersect_key($snapshot, array_flip(self::KEPT)));
    }

    /** The way kept for this plan, if any. */
    public function for(User $user, string $date, string $planId): ?PlannedTrip
    {
        return PlannedTrip::query()->where('user_id', $user->id)->where('date', $date)->where('target_id', $planId)->first();
    }

    public function forget(User $user, string $date, string $planId): void
    {
        PlannedTrip::query()->where('user_id', $user->id)->where('date', $date)->where('target_id', $planId)->delete();
    }
}
