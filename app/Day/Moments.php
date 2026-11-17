<?php

namespace App\Day;

use App\ContextEngine\BriefText;
use App\ContextEngine\DayBriefs;
use App\ContextEngine\DayImportance;
use App\ContextEngine\PlanRisks;
use App\Home\WhatsOn;
use App\Models\EventReminder;
use App\Models\NotificationPreference;
use App\Models\PlannedTrip;
use App\Models\User;
use App\Personalisation\Routines;
use Carbon\CarbonImmutable;

/**
 * The moments that matter in the next 36 hours, as data the iPhone schedules as its own
 * notifications (track, week 2: no push without the paid Apple account, so the phone schedules
 * them ahead and replaces them whenever it opens). Each has a stable id, so a moment that moved or
 * went replaces or removes the one scheduled; the words are the ones a push would carry.
 *
 * - leave: a planned way with "Remind me when to leave", five minutes before it leaves (they asked:
 *   quiet hours don't hold it);
 * - routine: a routine they confirmed (Routines), five minutes before its usual time on its days;
 * - event: an event they asked to be reminded of, when they asked;
 * - plan: a risk to a stop on their plan (a disruption, a delay, rain, closed), an hour before it;
 * - brief: the morning brief of a day that isn't quiet, and the evening one before an important day.
 *
 * Only the kinds they keep on (Settings › Notifications), and outside their quiet hours.
 */
final class Moments
{
    /** How far ahead the phone schedules. */
    public const HOURS = 36;

    /** A risk is told this long before the stop it's about. */
    private const RISK_LEAD_MINUTES = 60;

    /** The notification kind each plan risk belongs to. */
    private const RISK_KINDS = ['disruption' => 'transit', 'delay' => 'transit', 'rain' => 'weather', 'hazard' => 'weather', 'closed' => 'transit'];

    public function __construct(private PlanRisks $risks, private DayBriefs $briefs, private DayImportance $importance) {}

    /**
     * @return list<array{id: string, kind: string, at: string, title: string, body: string, link: string}>
     */
    public function for(User $user, CarbonImmutable $now): array
    {
        $now = $now->setTimezone('Europe/Berlin');
        $until = $now->addHours(self::HOURS);
        $state = NotificationPreference::stateFor($user);
        $quiet = fn (CarbonImmutable $at): bool => NotificationPreference::inQuietHours($at->setTimezone('Europe/Berlin')->format('H:i'), $state['quiet_from'], $state['quiet_to']);

        $moments = [
            ...$this->leaving($user, $now, $until),
            ...$this->routines($user, $now, $until),
            ...($user->wantsNotification('events') ? $this->reminders($user, $now, $until) : []),
            ...$this->planRisks($user, $now, $until),
            ...($user->wantsNotification('brief') ? $this->briefs($user, $now, $until) : []),
        ];

        return collect($moments)
            // What they asked for comes when they asked (a way or a routine to leave for); the rest keeps their quiet hours.
            ->reject(fn (array $m): bool => ! in_array($m['kind'], ['leave', 'routine'], true) && $quiet($m['at']))
            ->sortBy(fn (array $m): int => $m['at']->getTimestamp())
            ->map(fn (array $m): array => [...$m, 'at' => $m['at']->toIso8601String()])
            ->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function leaving(User $user, CarbonImmutable $now, CarbonImmutable $until): array
    {
        return PlannedTrip::query()->where('user_id', $user->id)->where('remind', true)
            ->where('depart_at', '>', $now->utc())->where('depart_at', '<=', $until->utc())
            ->orderBy('depart_at')->get()
            ->map(function (PlannedTrip $trip) use ($now): array {
                $shared = $trip->toShared();
                $ride = $shared['ride'];

                return ['id' => "leave:{$trip->id}:{$trip->depart_at->getTimestamp()}", 'kind' => 'leave', 'at' => $trip->remindAt()->setTimezone('Europe/Berlin')->max($now),
                    'title' => "Time to leave for {$trip->title}",
                    'body' => "Leave at {$shared['depart_time']}".($ride !== null ? ' · '.PlannedTrip::rideName($ride)." at {$ride['time']}" : ''),
                    'link' => '/travel'];
            })->all();
    }

    /** @return list<array<string, mixed>> a confirmed routine's leave time, five minutes before, on its days */
    private function routines(User $user, CarbonImmutable $now, CarbonImmutable $until): array
    {
        $moments = [];
        if (! Routines::learns($user)) {
            return [];
        }
        foreach (app(Routines::class)->confirmed($user) as $routine) {
            if (! $routine['remind']) {
                continue;
            }
            foreach ([$now->startOfDay(), $now->startOfDay()->addDay()] as $day) {
                $leave = $day->setTimeFromTimeString($routine['at']);
                $at = $leave->subMinutes(PlannedTrip::REMIND_BEFORE_MINUTES);
                if (in_array($day->dayOfWeekIso, $routine['weekdays'], true) && $at->greaterThan($now) && $at->lessThanOrEqualTo($until)) {
                    $moments[] = ['id' => "routine:{$routine['id']}:{$day->toDateString()}", 'kind' => 'routine', 'at' => $at,
                        'title' => "Time to leave for {$routine['to_label']}", 'body' => "You usually leave at {$routine['at']}.", 'link' => '/travel'];
                }
            }
        }

        return $moments;
    }

    /** @return list<array<string, mixed>> */
    private function reminders(User $user, CarbonImmutable $now, CarbonImmutable $until): array
    {
        return EventReminder::query()->with('event')->where('user_id', $user->id)->whereNull('sent_at')
            ->where('remind_at', '>', $now->utc())->where('remind_at', '<=', $until->utc())
            ->get()->filter(fn (EventReminder $r): bool => $r->event !== null)
            ->map(function (EventReminder $reminder): array {
                $event = $reminder->event;
                $start = CarbonImmutable::parse($reminder->occurrence_start)->setTimezone('Europe/Berlin');

                return ['id' => "event:{$reminder->id}:{$start->getTimestamp()}", 'kind' => 'event', 'at' => CarbonImmutable::parse($reminder->remind_at)->setTimezone('Europe/Berlin'),
                    'title' => $event->title_en ?: $event->title,
                    'body' => ($event->location_name ? "At {$event->location_name}. " : '')."Starts at {$start->format('H:i')}.",
                    'link' => WhatsOn::eventUrl($event->id, $start)];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> the worst risk to each stop, an hour before it */
    private function planRisks(User $user, CarbonImmutable $now, CarbonImmutable $until): array
    {
        $rank = ['critical' => 4, 'major' => 3, 'moderate' => 2, 'minor' => 1];

        return collect([$now->startOfDay(), $now->startOfDay()->addDay()])
            ->flatMap(fn (CarbonImmutable $day): array => $this->risks->for($user, $day))
            ->filter(fn (array $risk): bool => $risk['at']->greaterThan($now) && $risk['at']->lessThanOrEqualTo($until)
                && $user->wantsNotification(self::RISK_KINDS[$risk['kind']] ?? 'transit'))
            ->groupBy(fn (array $risk): string => $risk['at']->toDateString().':'.$risk['slot'])
            ->map(function ($stop, string $key) use ($rank, $now): array {
                $worst = $stop->sortByDesc(fn (array $risk): int => $rank[$risk['severity']] ?? 0)->first();

                return ['id' => "plan:{$key}:{$worst['kind']}", 'kind' => 'plan', 'at' => $worst['at']->subMinutes(self::RISK_LEAD_MINUTES)->max($now),
                    'title' => "{$worst['name']} at {$worst['start']}", 'body' => $worst['text'], 'link' => '/today'];
            })->values()->all();
    }

    /** @return list<array<string, mixed>> the morning briefs ahead, and the evening one before an important day */
    private function briefs(User $user, CarbonImmutable $now, CarbonImmutable $until): array
    {
        $moments = [];
        foreach ([$now->startOfDay(), $now->startOfDay()->addDay()] as $day) {
            $morning = $this->importance->band($user, $day) === DayImportance::QUIET ? null : $this->briefs->morningAt($user, $day);
            if ($morning !== null && $morning->greaterThan($now) && $morning->lessThanOrEqualTo($until)) {
                $moments[] = $this->brief($user, $day, DayBriefs::MORNING, $morning, 'Your '.$day->isoFormat('dddd'));
            }
            $evening = $day->setTimeFromTimeString(DayBriefs::EVENING_AT);
            if ($evening->greaterThan($now) && $evening->lessThanOrEqualTo($until)
                && $this->importance->band($user, $day->addDay()) === DayImportance::IMPORTANT) {
                $moments[] = $this->brief($user, $day->addDay(), DayBriefs::EVENING, $evening, 'Tomorrow, '.$day->addDay()->isoFormat('dddd'));
            }
        }

        return array_values(array_filter($moments));
    }

    /** @return array<string, mixed>|null a brief in the template's words (written ahead, so never by the AI) */
    private function brief(User $user, CarbonImmutable $day, string $kind, CarbonImmutable $at, string $title): ?array
    {
        $items = $this->briefs->items($user, $day, $kind);

        return $items === [] ? null : ['id' => "brief:{$kind}:{$day->toDateString()}", 'kind' => 'brief', 'at' => $at,
            'title' => $title, 'body' => implode("\n", BriefText::template($items)), 'link' => '/today'];
    }
}
