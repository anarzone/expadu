<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\Redis;

class EventTrackingService
{
    /**
     * Track a user event with an optional payload.
     * For location_ping events, also checks proximity to known spots.
     *
     * @param  array<string, mixed>  $payload
     */
    public function track(User $user, string $eventType, array $payload = []): void
    {
        UserEvent::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'payload' => $payload ?: null,
            'session_id' => session()->getId(),
        ]);

        // Auto-detect nearby spots from GPS location + store in Redis for history
        if ($eventType === 'location_ping' && isset($payload['lat'], $payload['lng'])) {
            $this->storeLocationInRedis($user, (float) $payload['lat'], (float) $payload['lng'], $payload['accuracy'] ?? null);
            $this->detectNearbySpots($user, (float) $payload['lat'], (float) $payload['lng']);
        }
    }

    /**
     * Check if user is near a known spot (within 100m).
     * Records a spot_proximity event — passive, no user action needed.
     * Throttled: max 1 per spot per 30 minutes.
     */
    private function detectNearbySpots(User $user, float $lat, float $lng): void
    {
        // Find spots within ~100m using simple coordinate distance
        // 0.001 degrees ≈ 111m at this latitude
        $nearbySpots = Spot::whereBetween('lat', [$lat - 0.001, $lat + 0.001])
            ->whereBetween('lng', [$lng - 0.0015, $lng + 0.0015])
            ->limit(3)
            ->get(['id', 'name', 'lat', 'lng']);

        foreach ($nearbySpots as $spot) {
            // Verify actual distance (haversine)
            $dist = $this->distanceMeters($lat, $lng, (float) $spot->lat, (float) $spot->lng);
            if ($dist > 100) {
                continue;
            }

            // Throttle: skip if we recorded proximity to this spot in the last 30 min
            $recentExists = UserEvent::where('user_id', $user->id)
                ->where('event_type', 'spot_proximity')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->whereRaw("payload->>'spot_id' = ?", [(string) $spot->id])
                ->exists();

            if ($recentExists) {
                continue;
            }

            UserEvent::create([
                'user_id' => $user->id,
                'event_type' => 'spot_proximity',
                'payload' => [
                    'spot_id' => $spot->id,
                    'spot_name' => $spot->name,
                    'lat' => $spot->lat,
                    'lng' => $spot->lng,
                    'distance_m' => round($dist),
                ],
                'session_id' => session()->getId(),
            ]);
        }
    }

    /**
     * Store location ping in Redis for 1-week history.
     *
     * NOTICE: Location data is stored in Redis (not database) with a 7-day TTL.
     * This is intentional for:
     * - Analyzing movement patterns to improve commute suggestions
     * - Detecting frequently visited places for routine auto-detection
     * - Debugging location-related issues (e.g. wrong stop shown)
     *
     * Data stored per entry: {lat, lng, accuracy, timestamp}
     * Key format: location_history:{user_id} (Redis sorted set, scored by timestamp)
     * Auto-expires: 7 days from last write via TTL refresh
     *
     * To query: Redis::zrangebyscore("location_history:{userId}", $from, $to)
     */
    private function storeLocationInRedis(User $user, float $lat, float $lng, ?float $accuracy): void
    {
        try {
            $key = "location_history:{$user->id}";
            $timestamp = now()->timestamp;

            // Deduplicate: skip if last entry was <60s ago and <50m away
            $lastEntries = Redis::zrevrange($key, 0, 0, 'WITHSCORES');
            if (! empty($lastEntries)) {
                $lastJson = array_key_first($lastEntries);
                $lastScore = (int) $lastEntries[$lastJson];

                if ($timestamp - $lastScore < 60) {
                    $last = json_decode($lastJson, true);
                    if ($last && $this->distanceMeters($lat, $lng, (float) $last['lat'], (float) $last['lng']) < 50) {
                        return;
                    }
                }
            }

            $entry = json_encode([
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'accuracy' => $accuracy ? round($accuracy) : null,
                'at' => now()->toIso8601String(),
            ]);

            Redis::zadd($key, $timestamp, $entry);

            // Remove entries older than 7 days
            Redis::zremrangebyscore($key, 0, now()->subWeek()->timestamp);

            // Refresh TTL to 7 days from now
            Redis::expire($key, 604800); // 7 * 24 * 60 * 60
        } catch (\Throwable) {
            // Redis unavailable — skip location storage silently
        }
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
