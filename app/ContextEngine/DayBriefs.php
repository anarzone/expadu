<?php

namespace App\ContextEngine;

use App\Composer\WorkspaceStore;
use App\Models\EventReminder;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Services\GermanHolidayService;
use Carbon\CarbonImmutable;

/**
 * The day's briefs (owner, 26 Oct 2026): tier-2 things said together, by how important the day
 * is (DayImportance). A quiet day has none. A normal one has a morning brief, 45 minutes before
 * they need to be off to their first commitment and never before 06:30. An important one has
 * that, and one the evening before at 19:00. What goes in comes from what we hold, each item
 * with its own words and the facts they come from; Paperwork only as "needs attention".
 */
final class DayBriefs
{
    public const MORNING = 'morning';

    public const EVENING = 'evening';

    /** Never earlier than this (coordinator, 26 Oct 2026). */
    private const EARLIEST = '06:30';

    /** With nothing timed in the day, the morning brief comes now. */
    private const UNTIMED = '07:30';

    /** The evening brief before an important day. */
    private const EVENING_AT = '19:00';

    /** Said before this, or not at all (a missed run catches up within it; quiet hours start at 22). */
    private const WINDOW_MINUTES = 120;

    /**
     * Minutes before a commitment's time that they need the brief: 45 before they set off. A
     * planned way's time is when it leaves; Work's and an event's are when they're due there, so
     * the way there (an hour, until Settings knows theirs: phase 3) comes first.
     */
    private const LEAD = ['planned_trip' => 45, 'work' => 105, 'event' => 105, 'plan_stop' => 105, 'plan_stop_fixed' => 105];

    /** What comes first in a brief: what threatens the day, then the day in order, then the rest. */
    private const RANK = ['risk' => 0, 'timed' => 1, 'paperwork' => 2, 'weather' => 3, 'shops' => 4];

    public function __construct(private DayImportance $importance, private ActionBus $bus, private WorkspaceStore $workspace, private PlanRisks $planRisks) {}

    /**
     * The brief due now, if one is: which, for which day, and when it was due.
     *
     * @return array{kind: string, day: CarbonImmutable, at: CarbonImmutable}|null
     */
    public function due(User $user, CarbonImmutable $now): ?array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $today = $now->startOfDay();
        $band = $this->importance->band($user, $today);
        $morning = $band === DayImportance::QUIET ? null : $this->morningAt($user, $today);
        if ($morning !== null && $now->betweenIncluded($morning, $morning->addMinutes(self::WINDOW_MINUTES))) {
            return ['kind' => self::MORNING, 'day' => $today, 'at' => $morning];
        }
        $evening = $today->setTimeFromTimeString(self::EVENING_AT);
        if ($now->betweenIncluded($evening, $evening->addMinutes(self::WINDOW_MINUTES))
            && $this->importance->band($user, $today->addDay()) === DayImportance::IMPORTANT) {
            return ['kind' => self::EVENING, 'day' => $today->addDay(), 'at' => $evening];
        }

        return null;
    }

    /**
     * When the morning brief is due: the time they chose (phase 3: notification_preferences.brief_at),
     * else 45 minutes before they set off to their first commitment, not before 06:30.
     */
    public function morningAt(User $user, CarbonImmutable $day): CarbonImmutable
    {
        $chosen = $user->notificationPreference?->brief_at;
        if (is_string($chosen) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $chosen) === 1) {
            return $day->setTimeFromTimeString(substr($chosen, 0, 5));
        }
        $leads = collect($this->importance->for($user, $day)['commitments'])->filter(fn (array $c): bool => $c['at'] !== null)
            ->map(fn (array $c): CarbonImmutable => $day->setTimeFromTimeString($c['at'])->subMinutes(self::LEAD[$c['kind']] ?? 45));
        $at = $leads->isEmpty() ? $day->setTimeFromTimeString(self::UNTIMED) : $leads->min();

        return $at->max($day->setTimeFromTimeString(self::EARLIEST));
    }

    /**
     * What a brief says, most important first: each item's own line, the facts it comes from (all a
     * written line may use), whether it's theirs only (never sent to an AI) and its order; and, for
     * Today's "Needs you" (TodayNeeds), its push type, severity, a short why, a link and what it's
     * about (never shown). One list for the brief, push and Today.
     *
     * @return list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int, type: ?string, severity: string, why: ?string, link: string, about: string, at: ?string}>
     */
    public function items(User $user, CarbonImmutable $day, string $kind): array
    {
        $day = $day->setTimezone('Europe/Berlin')->startOfDay();
        $today = $day->isSameDay(CarbonImmutable::now('Europe/Berlin'));
        $actions = $this->bus->topK($user->id, 50);
        $items = [];
        $add = function (string $kind, string $text, string $facts, bool $own, int $rank, array $more = []) use (&$items): void {
            $items[] = ['id' => 'i'.(count($items) + 1), 'kind' => $kind, 'text' => $text, 'facts' => $facts, 'own' => $own, 'rank' => $rank,
                'type' => $more['type'] ?? null, 'severity' => $more['severity'] ?? 'minor', 'why' => $more['why'] ?? null,
                'link' => $more['link'] ?? '/today', 'about' => $more['about'] ?? $kind, 'at' => $more['at'] ?? null];
        };
        $journey = fn (string $activity): string => '/timetable?'.http_build_query(['view' => 'journey', 'activity' => $activity]);

        // What threatens the day: their planned ways changing; on the day itself, live disruptions on their lines and delays.
        foreach (PlannedTrip::query()->where('user_id', $user->id)->whereDate('date', $day->toDateString())->orderBy('depart_at')->get() as $trip) {
            $time = $trip->depart_at->setTimezone('Europe/Berlin')->format('H:i');
            $change = ['cancelled' => 'is cancelled', 'late' => 'is running late', 'notice' => 'has a KVB notice'][$trip->alerted_kind] ?? null;
            $way = ['link' => $journey((string) $trip->target_id), 'about' => "trip:{$trip->id}", 'at' => $trip->depart_at->setTimezone('Europe/Berlin')->toIso8601String()];
            $change !== null
                ? $add('trip_change', "Your {$time} way to {$trip->title} {$change}.", "Planned way to \"{$trip->title}\", leaving {$time}, {$change}.", true, self::RANK['risk'] * 10000,
                    [...$way, 'type' => 'planned_trip_change', 'severity' => ['cancelled' => 'critical', 'late' => 'major'][$trip->alerted_kind] ?? 'moderate'])
                : $add('trip', "{$time} leave for {$trip->title}.", "Planned way to \"{$trip->title}\", leaving {$time}.", true, self::RANK['timed'] * 10000 + self::minutes($time),
                    [...$way, 'type' => 'planned_trip_leave']);
        }
        if ($today) {
            foreach ($actions as $action) {
                $lines = implode(', ', array_slice((array) ($action->payload['lines'] ?? []), 0, 3));
                if ($action->type === 'transit_disruption' && ($action->payload['on_user_line'] ?? false) === true && $lines !== '') {
                    $stops = implode(', ', array_slice((array) ($action->payload['stops_affected'] ?? []), 0, 2));
                    $text = "Line {$lines} disrupted".($stops !== '' ? " at {$stops}" : '').'.';
                    $add('disruption', $text, "KVB disruption on line {$lines}".($stops !== '' ? ", stops affected: {$stops}" : '').', one of their lines.', false, self::RANK['risk'] * 10000,
                        ['type' => 'transit_disruption', 'severity' => $action->severity, 'why' => 'On one of your lines.', 'link' => '/timetable', 'about' => $action->actionKey]);
                } elseif ($action->type === 'transit_delay' && is_string($action->payload['line'] ?? null) && (int) ($action->payload['delay_min'] ?? 0) > 0) {
                    $delay = (int) $action->payload['delay_min'];
                    $stop = (string) ($action->payload['stop_id'] ?? '');
                    $add('delay', "Line {$action->payload['line']} running {$delay} min late".($stop !== '' ? " at {$stop}" : '').'.',
                        "Line {$action->payload['line']} running {$delay} min late".($stop !== '' ? " at {$stop}" : '').', one of their lines.', false, self::RANK['risk'] * 10000 + 1,
                        ['type' => 'transit_delay', 'severity' => $action->severity, 'why' => 'On one of your lines.', 'link' => '/timetable', 'about' => $action->actionKey]);
                } elseif ($action->type === 'weather_alert' && is_string($action->payload['alert']['summary'] ?? null)) {
                    $add('weather', $action->payload['alert']['summary'].'.', 'Weather today: '.$action->payload['alert']['summary'].'.', false, self::RANK['weather'] * 10000,
                        ['type' => 'weather_alert', 'severity' => $action->severity, 'link' => '/timetable', 'about' => $action->actionKey]);
                }
            }
        }

        // What threatens the stops of their plan (PlanRisks): closed, weather while outdoors, a disruption or delay near one.
        foreach ($this->planRisks->for($user, $day) as $risk) {
            $add('plan_'.$risk['kind'], $risk['text'], $risk['facts'], true, self::RANK['risk'] * 10000 + 2 + self::minutes($risk['start']), [
                'type' => ['closed' => 'plan_closed', 'disruption' => 'plan_disruption', 'delay' => 'plan_delay'][$risk['kind']] ?? 'plan_weather',
                'severity' => $risk['severity'], 'why' => "In your plan at {$risk['start']}.", 'link' => $journey($risk['slot']), 'about' => "plan:{$risk['slot']}:{$risk['kind']}",
                'at' => $risk['at']->toIso8601String()]);
        }

        // The day in order: events they set a reminder for or joined, and their plan.
        foreach ($this->events($user, $day) as [$title, $start, $venue]) {
            $add('event', "{$start} {$title}".($venue !== '' ? " at {$venue}" : '').'.', "An event they're going to: \"{$title}\", starting {$start}".($venue !== '' ? " at {$venue}" : '').'.', true, self::RANK['timed'] * 10000 + self::minutes($start),
                ['type' => 'event_reminder', 'link' => '/events']);
        }
        $stops = collect($this->workspace->plan($user, $day->toDateString())['slots'] ?? [])->sortBy('start_at')->values();
        if ($stops->isNotEmpty()) {
            $first = $stops->first();
            $count = $stops->count();
            $add('plan', "Your plan: {$count} ".($count === 1 ? 'stop' : 'stops')." from {$first['start_time']}, first {$first['name']}.",
                "Their plan for the day: {$count} ".($count === 1 ? 'stop' : 'stops').", the first \"{$first['name']}\" at {$first['start_time']}.", true, self::RANK['timed'] * 10000 + self::minutes((string) $first['start_time']));
        }

        // Paperwork only as needing attention, nothing of what; overdue or critical, it leads the brief
        // (coordinator, 26 Oct 2026).
        $commitments = collect($this->importance->for($user, $day)['commitments'])->pluck('kind');
        $paperwork = ['type' => 'bureaucracy_task', 'link' => '/bureaucracy', 'about' => 'paperwork'];
        if ($commitments->contains('paperwork_urgent')) {
            $add('paperwork', 'Your Paperwork needs attention.', 'Their Paperwork needs attention.', true, -1, [...$paperwork, 'severity' => 'critical']);
        } elseif ($commitments->contains('paperwork') || collect($actions)->contains(fn (ScoredAction $a): bool => $a->type === 'bureaucracy_task')) {
            $add('paperwork', 'Your Paperwork needs attention.', 'Their Paperwork needs attention.', true, self::RANK['paperwork'] * 10000, [...$paperwork, 'severity' => 'moderate']);
        }

        // The evening before: shops closed tomorrow.
        $holidays = app(GermanHolidayService::class);
        if ($kind === self::EVENING && ($day->isSunday() || $holidays->isHoliday($day))) {
            $why = $holidays->getHolidayName($day) ?? 'Sunday';
            $add('shops', "Shops are closed tomorrow ({$why}).", "Shops are closed tomorrow: {$why}.", false, self::RANK['shops'] * 10000, ['type' => 'market_closure']);
        }

        usort($items, fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return $items;
    }

    /**
     * Events that day they set a reminder for or joined: title, start, venue.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function events(User $user, CarbonImmutable $day): array
    {
        $reminded = EventReminder::query()->where('user_id', $user->id)->whereBetween('occurrence_start', [$day, $day->endOfDay()])->with('event')->get()
            ->filter(fn (EventReminder $r): bool => $r->event !== null)
            ->map(fn (EventReminder $r): array => [(int) $r->event_id, (string) ($r->event->title_en ?: $r->event->title), CarbonImmutable::instance($r->occurrence_start), (string) $r->event->location_name]);
        $joined = $user->attendingEvents()->where('status', 'active')->whereBetween('starts_at', [$day, $day->endOfDay()])->get()
            ->map(fn ($event): array => [(int) $event->id, (string) ($event->title_en ?: $event->title), CarbonImmutable::instance($event->starts_at), (string) $event->location_name]);

        return $reminded->concat($joined)->unique(fn (array $e): int => $e[0])->sortBy(fn (array $e): int => $e[2]->getTimestamp())
            ->map(fn (array $e): array => [$e[1], $e[2]->setTimezone('Europe/Berlin')->format('H:i'), $e[3]])->values()->all();
    }

    private static function minutes(string $time): int
    {
        return preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) === 1 ? (int) $m[1] * 60 + (int) $m[2] : 0;
    }
}
