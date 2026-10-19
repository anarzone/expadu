<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Controls push notification frequency to prevent user fatigue.
 *
 * Types are ScoredAction types — ActionBus is the only caller. These lists used
 * to name types no producer emits (rhine, buergeramt, critical_disruption,
 * weather_commute), so none of them ever matched: critical disruptions were
 * held by quiet hours and weather pushed outside commute windows.
 *
 * Three tiers:
 * - Tier 1 (push anytime): Bürgeramt slots, critical disruptions
 * - Tier 2 (push during commute only): transit delays, weather alerts
 * - Tier 3 (in-app only, never push): Rhine level
 */
class NotificationThrottle
{
    // Hard caps
    private const MAX_PER_DAY = 5;

    private const MAX_PER_HOUR = 2;

    private const MIN_INTERVAL_SECONDS = 900; // 15 minutes

    private const QUIET_START = 22; // 10 PM

    private const QUIET_END = 6;   // 6 AM

    // Types that should NEVER be pushed — in-app alerts only
    private const IN_APP_ONLY = [
        'rhine_level',
    ];

    // Types exempt from quiet hours (and transit_disruption when critical — see quietExempt())
    private const QUIET_EXEMPT = [
        'buergeramt_slot',
    ];

    // Types that only push during commute windows (6-9am, 4-7pm)
    private const COMMUTE_ONLY = [
        'transit_delay',
        'weather_alert',
    ];

    /** ScoredAction type → NotificationPreference key (the keys NotificationPreference::defaults() exposes). */
    private const PREF_KEYS = [
        'transit_disruption' => 'transit',
        'transit_delay' => 'transit',
        'weather_alert' => 'weather',
        'rhine_level' => 'rhine',
        'buergeramt_slot' => 'burgeramt',
        'bureaucracy_task' => 'checklist',
        'permanent_residency_eligible' => 'checklist',
        'market_closure' => 'events',
    ];

    /**
     * Check if a push notification can be sent to this user.
     * Returns false if throttled — the caller should still create an in-app Alert.
     */
    public static function canPush(User $user, string $type, ?string $severity = null): bool
    {
        // Tier 3: never push
        if (in_array($type, self::IN_APP_ONLY, true)) {
            return false;
        }

        // Check user notification preferences
        $prefKey = self::PREF_KEYS[$type] ?? null;
        if ($prefKey && ! $user->wantsNotification($prefKey)) {
            return false;
        }

        $hour = now()->hour;

        // Quiet hours check (exempt for Tier 1 types)
        if (! self::quietExempt($type, $severity)) {
            if ($hour >= self::QUIET_START || $hour < self::QUIET_END) {
                return false;
            }
        }

        // Commute window check for Tier 2 types
        if (in_array($type, self::COMMUTE_ONLY, true)) {
            $isMorningCommute = $hour >= 6 && $hour <= 9;
            $isEveningCommute = $hour >= 16 && $hour <= 19;
            if (! $isMorningCommute && ! $isEveningCommute) {
                return false;
            }
        }

        try {
            $userId = $user->id;

            // Check minimum interval
            $lastSent = Redis::get("notif_throttle:last:{$userId}");
            if ($lastSent && (now()->timestamp - (int) $lastSent) < self::MIN_INTERVAL_SECONDS) {
                return false;
            }

            // Check hourly cap
            $hourKey = "notif_throttle:hour:{$userId}:".now()->format('Y-m-d-H');
            $hourCount = (int) Redis::get($hourKey);
            if ($hourCount >= self::MAX_PER_HOUR) {
                return false;
            }

            // Check daily cap
            $dayKey = "notif_throttle:day:{$userId}:".now()->format('Y-m-d');
            $dayCount = (int) Redis::get($dayKey);
            if ($dayCount >= self::MAX_PER_DAY) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return true; // Redis down — allow notification
        }
    }

    /**
     * Record that a push was sent — update throttle counters.
     */
    public static function recordSent(User $user): void
    {
        try {
            $userId = $user->id;

            Redis::set("notif_throttle:last:{$userId}", now()->timestamp);
            Redis::expire("notif_throttle:last:{$userId}", self::MIN_INTERVAL_SECONDS);

            $hourKey = "notif_throttle:hour:{$userId}:".now()->format('Y-m-d-H');
            Redis::incr($hourKey);
            Redis::expire($hourKey, 3600);

            $dayKey = "notif_throttle:day:{$userId}:".now()->format('Y-m-d');
            Redis::incr($dayKey);
            Redis::expire($dayKey, 86400);
        } catch (\Throwable) {
            // Redis unavailable
        }
    }

    /** A critical disruption on the user's line can't wait until morning. */
    private static function quietExempt(string $type, ?string $severity): bool
    {
        return in_array($type, self::QUIET_EXEMPT, true)
            || ($type === 'transit_disruption' && $severity === 'critical');
    }
}
