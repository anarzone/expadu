<?php

namespace App\Home;

use App\ContextEngine\DayBriefs;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * "Needs you" on Today (owner, 26 Oct 2026): what threatens their day and what can't wait, the
 * same things a push or the day's brief says, from the same items in the same order (DayBriefs)
 * through the same gate (NotificationThrottle::eligible), so Today never says what push wouldn't,
 * and the reverse. No budget, no quiet hours, no commute windows: those say when a push may go,
 * and Today shows what's true now. Paperwork says only that it needs attention (the strip's own
 * detail is TodayPaperwork's). The day's own plan and its trips are Today's day section, not here.
 * The shape is Build session 1's for its strip.
 */
final class TodayNeeds
{
    /** What needs them now, of the brief's items. */
    private const NEEDS = ['trip_change', 'disruption', 'delay', 'weather', 'plan_closed', 'plan_disruption', 'plan_delay', 'plan_rain', 'plan_hazard', 'paperwork'];

    /** Kinds as Session 1's strip names them for its icons (components/today/needs.tsx); the rest as they are. */
    private const KINDS = ['plan_rain' => 'rain_outdoor', 'plan_disruption' => 'disruption'];

    /** A planned way leaving this soon says "Leave now", above everything. */
    private const LEAVE_WITHIN_MINUTES = 15;

    /** What pushes on its own when it's this close (notifications:watch-plans). */
    private const SOON_MINUTES = 120;

    public function __construct(private DayBriefs $briefs) {}

    /**
     * Today's strip's shape (Build session 1, 26 Oct 2026): null when the engine couldn't say, so
     * nothing claims a calm day it didn't check; else the items, ranked, and when they were checked.
     *
     * @return array{items: list<array{id: string, tier: int, kind: string, headline: string, detail: ?string, at: ?string, severity: string, href: ?string}>, checked_at: string}|null
     */
    public function for(User $user): ?array
    {
        $now = CarbonImmutable::now('Europe/Berlin');
        try {
            $items = $this->items($user, $now);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        return ['items' => $items, 'checked_at' => $now->toIso8601String()];
    }

    /**
     * @return list<array{id: string, tier: int, kind: string, headline: string, detail: ?string, at: ?string, severity: string, href: ?string}>
     */
    private function items(User $user, CarbonImmutable $now): array
    {
        $leaving = PlannedTrip::query()->where('user_id', $user->id)->whereDate('date', $now->toDateString())
            ->where('depart_at', '>', $now->utc())->where('depart_at', '<=', $now->utc()->addMinutes(self::LEAVE_WITHIN_MINUTES))
            ->where(fn ($q) => $q->whereNull('alerted_kind')->orWhere('alerted_kind', '!=', 'cancelled'))->orderBy('depart_at')->get()
            ->map(fn (PlannedTrip $trip): array => ['kind' => 'leave_now', 'type' => 'planned_trip_leave', 'severity' => 'major',
                'text' => "Leave now for {$trip->title}.", 'why' => 'Your way leaves at '.$trip->depart_at->setTimezone('Europe/Berlin')->format('H:i').'.',
                'link' => '/timetable?'.http_build_query(['view' => 'journey', 'activity' => (string) $trip->target_id]), 'about' => "trip:{$trip->id}",
                'at' => $trip->depart_at->setTimezone('Europe/Berlin')->toIso8601String()]);
        $needs = collect($this->briefs->items($user, $now->startOfDay(), DayBriefs::MORNING))->filter(fn (array $item): bool => in_array($item['kind'], self::NEEDS, true));

        return $leaving->concat($needs)
            ->filter(fn (array $item): bool => $item['type'] !== null && NotificationThrottle::eligible($user, $item['type']))
            ->map(fn (array $item): array => [
                // Which item it is today, never what it says: the same one keeps its id all day.
                'id' => substr(hash('sha256', $now->toDateString().'|'.$item['about']), 0, 16),
                'tier' => self::tier($item, $now),
                'kind' => self::KINDS[$item['kind']] ?? $item['kind'],
                'headline' => $item['text'],
                'detail' => $item['why'],
                'at' => $item['at'],
                'severity' => in_array($item['severity'], ['critical', 'major'], true) ? 'conflict' : 'attention',
                'href' => $item['link'],
            ])->values()->all();
    }

    /**
     * 1 when push would say it on its own now (leaving, a changed way, Paperwork that can't wait, a
     * critical or major problem, a plan stop's risk under two hours off); 2 when it waits for the brief.
     */
    private static function tier(array $item, CarbonImmutable $now): int
    {
        $soon = $item['at'] !== null && CarbonImmutable::parse($item['at'])->lessThanOrEqualTo($now->addMinutes(self::SOON_MINUTES));

        return match (true) {
            in_array($item['kind'], ['leave_now', 'trip_change'], true) => 1,
            str_starts_with($item['kind'], 'plan_') => $soon ? 1 : 2,
            $item['kind'] === 'paperwork' => $item['severity'] === 'critical' ? 1 : 2,
            default => in_array($item['severity'], ['critical', 'major'], true) ? 1 : 2,
        };
    }
}
