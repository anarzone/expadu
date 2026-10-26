<?php

namespace App\Support;

use App\ContextEngine\CommuteWindows;
use App\ContextEngine\DayImportance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Redis;

/**
 * The one gate every push goes through (owner, 26 Oct 2026): ActionBus::insert for the
 * ContextEngine's, ActionBus::deliver for the reminders a command or job has claimed.
 * Types are ScoredAction types.
 *
 * Tiers: 1 pushes now; 2 waits for the day's brief (DayBriefs), never pushed alone; 3 stays in
 * the app. Then, in order:
 * - Their preference for the type, off: never.
 * - Quiet hours (their own, from Settings, else 22–06): a critical disruption goes; what they
 *   asked for, or about the way they're taking (ON_TIME), goes when what it's about is within two
 *   hours, else waits until they end; anything else doesn't go.
 * - Paperwork pushes once a day at most, however many things need attention.
 * - Delays and weather only on their way (CommuteWindows: Home's and Work's arrive-by on their
 *   days, else 6–9 and 16–19).
 * - The day's budget, by how important the day is (DayImportance): 2 an hour and 5 a day, 3 and 8
 *   on an important day, 15 minutes apart. What they asked for is never held back by it, but counts.
 */
class NotificationThrottle
{
    private const MAX_PER_DAY = 5;

    private const MAX_PER_HOUR = 2;

    /** An important day's budget, that day only. */
    private const IMPORTANT_PER_DAY = 8;

    private const IMPORTANT_PER_HOUR = 3;

    private const MIN_INTERVAL_SECONDS = 900; // 15 minutes

    /** Quiet hours when they haven't set their own (notification_preferences.quiet_from / quiet_to). */
    private const QUIET_FROM = '22:00';

    private const QUIET_TO = '06:00';

    /** How close what an ON_TIME push is about must be for it to go in quiet hours. */
    private const ON_TIME_WITHIN_MINUTES = 120;

    /** Tier 3: in the app only, never pushed. */
    private const IN_APP_ONLY = [
        'rhine_level',
    ];

    /** Tier 2: said in the day's brief (DayBriefs), never pushed alone. */
    private const IN_BRIEF = [
        'market_closure',
        'event_tomorrow',
    ];

    /**
     * What they asked for at a time they chose ("remind me when to leave", "remind me an hour
     * before"), or about the way they're taking: on time, so never held back by the budget.
     */
    private const ON_TIME = [
        'planned_trip_leave',
        'planned_trip_change',
        'trip_get_off',
        'event_reminder',
    ];

    // Types exempt from quiet hours (and transit_disruption when critical — see quietExempt())
    private const QUIET_EXEMPT = [
        'buergeramt_slot',
    ];

    /** Only while they're on their way (CommuteWindows). */
    private const COMMUTE_ONLY = [
        'transit_delay',
        'weather_alert',
    ];

    /** ScoredAction type → NotificationPreference key (the keys NotificationPreference::defaults() exposes). */
    private const PREF_KEYS = [
        'transit_disruption' => 'transit',
        'transit_delay' => 'transit',
        'planned_trip_change' => 'transit',
        'trip_get_off' => 'transit',
        'weather_alert' => 'weather',
        'rhine_level' => 'rhine',
        'buergeramt_slot' => 'burgeramt',
        'bureaucracy_task' => 'checklist',
        'permanent_residency_eligible' => 'checklist',
        'market_closure' => 'events',
        'event_reminder' => 'events',
        'event_tomorrow' => 'events',
        'day_brief' => 'brief',
        // What threatens a stop of their plan (PlanRisks), under the two hours before it.
        'plan_disruption' => 'transit',
        'plan_delay' => 'transit',
        'plan_weather' => 'weather',
    ];

    /** Whether it may push now; the caller still records it in the app. */
    public static function canPush(User $user, string $type, ?string $severity = null): bool
    {
        return self::decide($user, $type, $severity)->send;
    }

    /**
     * Whether a push may go now, wait, or not go at all.
     *
     * @param  CarbonInterface|null  $about  when what it's about happens (the way leaves, the event starts)
     */
    public static function decide(User $user, string $type, ?string $severity = null, ?CarbonInterface $about = null): PushDecision
    {
        if (in_array($type, self::IN_APP_ONLY, true)) {
            return PushDecision::drop('in_app_only');
        }
        if (in_array($type, self::IN_BRIEF, true)) {
            return PushDecision::drop('in_brief');
        }

        $prefKey = self::PREF_KEYS[$type] ?? null;
        if ($prefKey && ! $user->wantsNotification($prefKey)) {
            return PushDecision::drop('preference_off');
        }

        $now = CarbonImmutable::now('Europe/Berlin');
        $onTime = in_array($type, self::ON_TIME, true);

        $quietUntil = self::quietUntil($user, $now);
        if ($quietUntil !== null && ! self::quietExempt($type, $severity)) {
            if (! $onTime) {
                return PushDecision::drop('quiet_hours');
            }
            if ($about === null || $about->greaterThan($now->addMinutes(self::ON_TIME_WITHIN_MINUTES))) {
                return PushDecision::hold('quiet_hours', $quietUntil);
            }
        }

        if ($type === 'bureaucracy_task' && self::paperworkToday($user, $now)) {
            return PushDecision::drop('paperwork_today');
        }

        if (in_array($type, self::COMMUTE_ONLY, true) && ! CommuteWindows::contains($user, $now)) {
            return PushDecision::drop('outside_commute');
        }

        if ($onTime) {
            return PushDecision::send();
        }

        return self::budget($user, $now);
    }

    /** Whether the day's budget has room: a slot later this hour or after the gap, or none today. */
    private static function budget(User $user, CarbonImmutable $now): PushDecision
    {
        $important = app(DayImportance::class)->band($user, $now) === DayImportance::IMPORTANT;

        try {
            $userId = $user->id;

            $lastSent = Redis::get("notif_throttle:last:{$userId}");
            if ($lastSent && ($now->timestamp - (int) $lastSent) < self::MIN_INTERVAL_SECONDS) {
                return PushDecision::hold('interval', CarbonImmutable::createFromTimestamp((int) $lastSent + self::MIN_INTERVAL_SECONDS, 'Europe/Berlin'));
            }

            $dayCount = (int) Redis::get("notif_throttle:day:{$userId}:".$now->format('Y-m-d'));
            if ($dayCount >= ($important ? self::IMPORTANT_PER_DAY : self::MAX_PER_DAY)) {
                return PushDecision::drop('daily_budget');
            }

            $hourCount = (int) Redis::get("notif_throttle:hour:{$userId}:".$now->format('Y-m-d-H'));
            if ($hourCount >= ($important ? self::IMPORTANT_PER_HOUR : self::MAX_PER_HOUR)) {
                return PushDecision::hold('hourly_budget', $now->addHour()->startOfHour());
            }

            return PushDecision::send();
        } catch (\Throwable) {
            return PushDecision::send(); // Redis down — allow notification
        }
    }

    /**
     * Record that a push was sent — update throttle counters; a Paperwork one, its once a day.
     */
    public static function recordSent(User $user, ?string $type = null): void
    {
        try {
            $userId = $user->id;
            $now = CarbonImmutable::now('Europe/Berlin');

            if ($type === 'bureaucracy_task') {
                Redis::set("notif_throttle:paperwork:{$userId}:".$now->format('Y-m-d'), 1);
                Redis::expire("notif_throttle:paperwork:{$userId}:".$now->format('Y-m-d'), 86400);
            }

            Redis::set("notif_throttle:last:{$userId}", $now->timestamp);
            Redis::expire("notif_throttle:last:{$userId}", self::MIN_INTERVAL_SECONDS);

            $hourKey = "notif_throttle:hour:{$userId}:".$now->format('Y-m-d-H');
            Redis::incr($hourKey);
            Redis::expire($hourKey, 3600);

            $dayKey = "notif_throttle:day:{$userId}:".$now->format('Y-m-d');
            Redis::incr($dayKey);
            Redis::expire($dayKey, 86400);
        } catch (\Throwable) {
            // Redis unavailable
        }
    }

    /**
     * When their quiet hours end, if it's quiet now; null when it isn't. Their own from Settings
     * (phase 3: notification_preferences.quiet_from / quiet_to), else 22:00–06:00; either may
     * span midnight.
     */
    public static function quietUntil(User $user, CarbonImmutable $now): ?CarbonImmutable
    {
        $own = $user->notificationPreference;
        $from = $now->setTimeFromTimeString(self::clock($own?->quiet_from) ?? self::QUIET_FROM);
        $to = $now->setTimeFromTimeString(self::clock($own?->quiet_to) ?? self::QUIET_TO);
        if ($from->equalTo($to)) {
            return null;
        }
        if ($from->lessThan($to)) {
            return $now->greaterThanOrEqualTo($from) && $now->lessThan($to) ? $to : null;
        }

        return match (true) {
            $now->greaterThanOrEqualTo($from) => $to->addDay(),
            $now->lessThan($to) => $to,
            default => null,
        };
    }

    /** "HH:MM" or "HH:MM:SS" as stored, or null. */
    private static function clock(mixed $time): ?string
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time) === 1 ? substr($time, 0, 5) : null;
    }

    private static function paperworkToday(User $user, CarbonImmutable $now): bool
    {
        try {
            return (bool) Redis::exists("notif_throttle:paperwork:{$user->id}:".$now->format('Y-m-d'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** A critical disruption on the user's line can't wait until morning. */
    private static function quietExempt(string $type, ?string $severity): bool
    {
        return in_array($type, self::QUIET_EXEMPT, true)
            || ($type === 'transit_disruption' && $severity === 'critical');
    }
}
