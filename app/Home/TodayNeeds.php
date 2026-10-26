<?php

namespace App\Home;

use App\ContextEngine\DayBriefs;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;

/**
 * "Needs you" on Today (owner, 26 Oct 2026): what threatens their day and what can't wait, the
 * same things a push or the day's brief says, from the same items in the same order (DayBriefs)
 * through the same gate (NotificationThrottle::eligible), so Today never says what push wouldn't,
 * and the reverse. No budget, no quiet hours, no commute windows: those say when a push may go,
 * and Today shows what's true now. Paperwork says only that it needs attention (the strip's own
 * detail is TodayPaperwork's). The day's own plan and its trips are Today's day section, not here.
 */
final class TodayNeeds
{
    /** What needs them now, of the brief's items. */
    private const NEEDS = ['trip_change', 'disruption', 'delay', 'weather', 'plan_closed', 'plan_disruption', 'plan_delay', 'plan_rain', 'plan_hazard', 'paperwork'];

    /** A planned way leaving this soon says "Leave now", above everything. */
    private const LEAVE_WITHIN_MINUTES = 15;

    public function __construct(private DayBriefs $briefs) {}

    /**
     * @return list<array{id: string, kind: string, severity: string, line: string, why: ?string, link: string}>
     */
    public function for(User $user): array
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        $leaving = PlannedTrip::query()->where('user_id', $user->id)->whereDate('date', $now->toDateString())
            ->where('depart_at', '>', $now->utc())->where('depart_at', '<=', $now->utc()->addMinutes(self::LEAVE_WITHIN_MINUTES))
            ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhere('alerted_kind', '!=', 'cancelled'))->orderBy('depart_at')->get()
            ->map(fn (PlannedTrip $trip): array => ['kind' => 'leave_now', 'type' => 'planned_trip_leave', 'severity' => 'major',
                'text' => "Leave now for {$trip->title}.", 'why' => 'Your way leaves at '.$trip->depart_at->setTimezone('Europe/Berlin')->format('H:i').'.',
                'link' => '/timetable?'.http_build_query(['view' => 'journey', 'activity' => (string) $trip->target_id]), 'about' => "trip:{$trip->id}"]);
        $needs = collect($this->briefs->items($user, $now->startOfDay(), DayBriefs::MORNING))->filter(fn (array $item): bool => in_array($item['kind'], self::NEEDS, true));

        return $leaving->concat($needs)
            ->filter(fn (array $item): bool => $item['type'] !== null && NotificationThrottle::eligible($user, $item['type']))
            ->map(fn (array $item): array => [
                // Which item it is today, never what it says: the same one keeps its id all day (TodayLearning).
                'id' => substr(hash('sha256', $now->toDateString().'|'.$item['about']), 0, 16),
                'kind' => $item['kind'], 'severity' => $item['severity'], 'line' => $item['text'], 'why' => $item['why'], 'link' => $item['link'],
            ])->values()->all();
    }
}
