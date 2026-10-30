<?php

namespace App\ContextEngine;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Composer\WorkspaceStore;
use App\Models\EventReminder;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Models\UserPlace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * How much a person's day holds (owner, 26 Oct 2026: "the frequency depends on how important the
 * day is"): its commitments, each weighed, times the worst risk to them, as a band. The band sets
 * the day's push budget (NotificationThrottle) and its briefs (DayBriefs). Worked out from what
 * we hold (their planned ways, Work's arrive-by, events they set a reminder for or joined, their
 * plan's stops and what threatens them, their Paperwork, live disruptions, delays and weather),
 * kept as plain data for half an hour, and forgotten when any of it changes.
 */
final class DayImportance
{
    public const QUIET = 'quiet';

    public const NORMAL = 'normal';

    public const IMPORTANT = 'important';

    /** What each commitment weighs (the AI session's sketch, approved 26 Oct 2026). */
    public const WEIGHTS = ['paperwork_urgent' => 5, 'paperwork' => 3, 'planned_trip' => 3, 'work' => 3, 'event' => 2, 'plan_stop_fixed' => 2, 'plan_stop' => 1];

    /** What a risk to the day multiplies it by; only the worst one counts. */
    public const RISKS = ['cancelled' => 3.0, 'critical_disruption' => 3.0, 'disruption' => 2.0, 'late' => 2.0, 'delay' => 2.0, 'closed' => 2.0, 'hazard' => 1.5, 'rain' => 1.5];

    /** A day scoring below this is quiet; from it, normal. */
    public const NORMAL_FROM = 3;

    /** From this, important. */
    public const IMPORTANT_FROM = 7;

    /** Kept this long at most; any change forgets it sooner. */
    private const MINUTES = 30;

    public function __construct(private ActionBus $bus, private WorkspaceStore $workspace, private PlanRisks $planRisks) {}

    /**
     * @return array{date: string, score: float, band: string, commitments: list<array{kind: string, at: ?string}>, risks: list<string>}
     */
    public function for(User $user, CarbonInterface $day): array
    {
        $day = CarbonImmutable::instance($day)->setTimezone('Europe/Berlin')->startOfDay();

        return Cache::remember(self::key($user->id, $day->toDateString()), now()->addMinutes(self::MINUTES), fn (): array => $this->score($user, $day));
    }

    public function band(User $user, CarbonInterface $day): string
    {
        return $this->for($user, $day)['band'];
    }

    /** Something in their day changed (a planned way, a reminder, Home or Work, their plan, a live alert): worked out again when next asked. */
    public static function forget(int $userId, CarbonInterface ...$days): void
    {
        foreach ($days === [] ? [now(), now()->addDay()] : $days as $day) {
            Cache::forget(self::key($userId, CarbonImmutable::instance($day)->setTimezone('Europe/Berlin')->toDateString()));
        }
    }

    /**
     * @return array{date: string, score: float, band: string, commitments: list<array{kind: string, at: ?string}>, risks: list<string>}
     */
    private function score(User $user, CarbonImmutable $day): array
    {
        $commitments = [...$this->paperwork($user, $day), ...$this->trips($user, $day), ...$this->work($user, $day), ...$this->events($user, $day), ...$this->stops($user, $day)];
        $risks = $this->risks($user, $day, $commitments !== []);
        $weight = array_sum(array_map(fn (array $c): int => self::WEIGHTS[$c['kind']], $commitments));
        $score = round($weight * max([1.0, ...array_map(fn (string $risk): float => self::RISKS[$risk], $risks)]), 1);
        // Overdue or critical Paperwork is never a quiet day (coordinator, 26 Oct 2026: a visa expired the day before scored quiet).
        $floor = collect($commitments)->contains('kind', 'paperwork_urgent');

        return [
            'date' => $day->toDateString(), 'score' => $score,
            'band' => match (true) {
                $score >= self::IMPORTANT_FROM => self::IMPORTANT,
                $score >= self::NORMAL_FROM || $floor => self::NORMAL,
                default => self::QUIET,
            },
            'commitments' => $commitments, 'risks' => array_values(array_unique($risks)),
        ];
    }

    /**
     * Their own Paperwork as its plan says it now (AccountHolderPlan: their own record only, while
     * verified and not stopped): a deadline or a document that's overdue or critical is a floor for
     * the day; an appointment that day counts too. Nothing of what it is leaves here.
     *
     * @return list<array{kind: string, at: ?string}>
     */
    private function paperwork(User $user, CarbonImmutable $day): array
    {
        $today = CarbonImmutable::now('Europe/Berlin')->startOfDay();
        if ($day->lessThan($today) || $day->greaterThan($today->addDay())) {
            return [];
        }
        try {
            $plan = app(AccountHolderPlan::class)->for($user);
        } catch (Throwable) {
            return [];
        }
        $rows = collect($plan === null ? [] : app(PlanAttention::class)->for($plan));
        $urgent = $rows->filter(fn (array $row): bool => in_array($row['kind'], ['legal_due', 'document_expiry'], true) && in_array($row['urgency'], ['overdue', 'critical'], true));
        $appointments = $rows->filter(fn (array $row): bool => $row['urgency'] === ($day->isSameDay($today) ? 'appointment_today' : 'appointment_tomorrow'));

        return [...$urgent->take(2)->map(fn (): array => ['kind' => 'paperwork_urgent', 'at' => null])->values()->all(),
            ...$appointments->take(2)->map(fn (): array => ['kind' => 'paperwork', 'at' => null])->values()->all()];
    }

    /** @return list<array{kind: string, at: ?string}> */
    private function trips(User $user, CarbonImmutable $day): array
    {
        return PlannedTrip::query()->where('user_id', $user->id)->whereDate('date', $day->toDateString())->get()
            ->map(fn (PlannedTrip $trip): array => ['kind' => 'planned_trip', 'at' => $trip->depart_at?->setTimezone('Europe/Berlin')->format('H:i')])->values()->all();
    }

    /** @return list<array{kind: string, at: ?string}> */
    private function work(User $user, CarbonImmutable $day): array
    {
        return UserPlace::query()->where('user_id', $user->id)->where('category', 'work')->whereNotNull('arrive_by')->get()
            ->filter(fn (UserPlace $work): bool => $work->isActiveOn($day))
            ->map(fn (UserPlace $work): array => ['kind' => 'work', 'at' => substr((string) $work->arrive_by, 0, 5)])->values()->all();
    }

    /**
     * Events that day they set a reminder for or joined, once each.
     *
     * @return list<array{kind: string, at: ?string}>
     */
    private function events(User $user, CarbonImmutable $day): array
    {
        $reminded = EventReminder::query()->where('user_id', $user->id)->whereBetween('occurrence_start', [$day, $day->endOfDay()])->get(['event_id', 'occurrence_start'])
            ->map(fn (EventReminder $r): array => [(int) $r->event_id, CarbonImmutable::instance($r->occurrence_start)]);
        $joined = $user->attendingEvents()->whereBetween('starts_at', [$day, $day->endOfDay()])->get(['events.id', 'events.starts_at'])
            ->map(fn ($event): array => [(int) $event->id, CarbonImmutable::instance($event->starts_at)]);

        return $reminded->concat($joined)->unique(fn (array $e): int => $e[0])
            ->map(fn (array $e): array => ['kind' => 'event', 'at' => $e[1]->setTimezone('Europe/Berlin')->format('H:i')])->values()->all();
    }

    /**
     * The stops of their plan for that day (Plans, Composer): one with a time of its own (an event,
     * an appointment, their own plan) weighs more.
     *
     * @return list<array{kind: string, at: ?string}>
     */
    private function stops(User $user, CarbonImmutable $day): array
    {
        return collect($this->workspace->plan($user, $day->toDateString())['slots'] ?? [])
            ->map(fn (array $slot): array => [
                'kind' => in_array($slot['type'] ?? null, ['event', 'appointment', 'personal'], true) || ($slot['is_appointment'] ?? false) ? 'plan_stop_fixed' : 'plan_stop',
                'at' => isset($slot['start_at']) ? CarbonImmutable::parse($slot['start_at'])->setTimezone('Europe/Berlin')->format('H:i') : null,
            ])->values()->all();
    }

    /**
     * What threatens the day: a change of plan on one of their planned ways; a disruption on their
     * line or a delay, live now, so today only; a weather hazard, or rain on a day with somewhere to be.
     *
     * @return list<string>
     */
    private function risks(User $user, CarbonImmutable $day, bool $committed): array
    {
        $risks = PlannedTrip::query()->where('user_id', $user->id)->whereDate('date', $day->toDateString())->whereIn('alerted_kind', ['notice', 'late', 'cancelled'])
            ->pluck('alerted_kind')->map(fn (string $kind): string => $kind === 'notice' ? 'disruption' : $kind)->all();
        // The stops of their plan (PlanRisks): closed that day, weather during an outdoor one, a disruption or delay near one.
        foreach ($this->planRisks->for($user, $day) as $risk) {
            $risks[] = $risk['kind'] === 'disruption' && $risk['severity'] === 'critical' ? 'critical_disruption' : $risk['kind'];
        }
        if (! $day->isSameDay(CarbonImmutable::now('Europe/Berlin'))) {
            return $risks;
        }
        foreach ($this->bus->topK($user->id, 50) as $action) {
            $risks[] = match (true) {
                $action->type === 'transit_disruption' && ($action->payload['on_user_line'] ?? false) === true => $action->severity === 'critical' ? 'critical_disruption' : 'disruption',
                $action->type === 'transit_delay' => 'delay',
                $action->type === 'weather_alert' && ($action->payload['alert']['kind'] ?? null) === 'hazard' => 'hazard',
                $action->type === 'weather_alert' && $committed => 'rain',
                default => null,
            };
        }

        return array_values(array_filter($risks));
    }

    private static function key(int $userId, string $date): string
    {
        return "day_importance:{$userId}:{$date}";
    }
}
