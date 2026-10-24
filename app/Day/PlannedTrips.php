<?php

namespace App\Day;

use App\Models\PlannedTrip;
use App\Models\User;
use App\Transit\DepartureTime;

/**
 * "Add to plan": the way picked for one of the person's plans on a day, with its "Remind me
 * when to leave". Plan a trip's Add to plan and Composer's plan_trip_for_later both keep it here.
 */
final class PlannedTrips
{
    /** The fields a kept way has, and what Undo puts back. */
    public const KEPT = ['title', 'journey', 'remind'];

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
