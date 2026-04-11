<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * Controls push notification frequency to prevent user fatigue.
 *
 * Three tiers:
 * - Tier 1 (push anytime): buergeramt, critical disruptions, event reminders
 * - Tier 2 (push during commute only): transit delays >10min, weather affecting commute
 * - Tier 3 (in-app only, never push): minor delays, weather updates, rhine level, news
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
        'rhine',
        'weather_info',
    ];

    // Types exempt from quiet hours
    private const QUIET_EXEMPT = [
        'buergeramt',
        'critical_disruption',
    ];

    // Types that only push during commute windows (6-9am, 4-7pm)
    private const COMMUTE_ONLY = [
        'transit_delay',
        'weather_commute',
    ];

    /**
     * Check if a push notification can be sent to this user.
     * Returns false if throttled — the caller should still create an in-app Alert.
     */
    public static function canPush(User $user, string $type): bool
    {
        // Tier 3: never push
        if (in_array($type, self::IN_APP_ONLY)) {
            return false;
        }

        // Check user notification preferences
        $prefKey = self::typeToPrefKey($type);
        if ($prefKey && ! $user->wantsNotification($prefKey)) {
            return false;
        }

        $hour = now()->hour;

        // Quiet hours check (exempt for Tier 1 types)
        if (! in_array($type, self::QUIET_EXEMPT)) {
            if ($hour >= self::QUIET_START || $hour < self::QUIET_END) {
                return false;
            }
        }

        // Commute window check for Tier 2 types
        if (in_array($type, self::COMMUTE_ONLY)) {
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

    private static function typeToPrefKey(string $type): ?string
    {
        return match ($type) {
            'transit_disruption', 'critical_disruption', 'transit_delay', 'leave_by_reminder' => 'transit',
            'buergeramt' => 'burgeramt',
            'weather_commute', 'weather_info' => 'weather',
            'market_closure', 'event_reminder' => 'events',
            'rhine' => 'rhine',
            default => null,
        };
    }
}
