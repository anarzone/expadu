<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Support\Facades\Redis;

class EventTrackingService
{
    public function __construct(private VeedelLocator $veedels, private LocationDataRetention $retention) {}

    /**
     * Track a user event with an optional payload.
     * For location_ping events, also checks proximity to known spots.
     *
     * @param  array<string, mixed>  $payload
     */
    public function track(User $user, string $eventType, array $payload = []): void
    {
        if ($eventType === 'location_ping') {
            $this->retention->withUserLock($user->id, function () use ($user, $eventType, $payload): void {
                // Serialize the permission check and writes with opt-out/deletion,
                // so a ping already in flight cannot refill a purged history.
                $user->load('userSetting');

                if ($user->setting('share_location')) {
                    $this->record($user, $eventType, $payload);
                }
            });

            return;
        }

        $this->record($user, $eventType, $payload);
    }

    /** @param array<string, mixed> $payload */
    private function record(User $user, string $eventType, array $payload): void
    {
        UserEvent::create([
            'user_id' => $user->id,
            'event_type' => $eventType,
            'payload' => in_array($eventType, ['location_ping', 'spot_proximity'], true) ? null : ($payload ?: null),
            'session_id' => session()->getId(),
        ]);

        // Auto-detect nearby spots from GPS location + store in Redis for history
        if ($eventType === 'location_ping' && isset($payload['lat'], $payload['lng'])) {
            $this->storeLocationInRedis(
                $user,
                (float) $payload['lat'],
                (float) $payload['lng'],
                $payload['accuracy'] ?? null,
                $payload['quality'] ?? null,
                $payload['rejected'] ?? null,
            );
            // Skip spot + Veedel detection for rejected readings
            if (empty($payload['rejected'])) {
                $this->detectNearbySpots($user, (float) $payload['lat'], (float) $payload['lng']);
                // Keep the user's home Veedel current as they relocate (jitter-throttled).
                $this->veedels->syncFromPing($user, (float) $payload['lat'], (float) $payload['lng']);
            }
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
            try {
                $key = "location_proximity:{$user->id}:{$spot->id}";
                if (Redis::exists($key)) {
                    continue;
                }
                Redis::setex($key, 1800, '1');
            } catch (\Throwable) {
                continue;
            }

            UserEvent::create([
                'user_id' => $user->id,
                'event_type' => 'spot_proximity',
                'payload' => null,
                'session_id' => session()->getId(),
            ]);
        }
    }

    /**
     * Store location ping in Redis for 1-week history.
     *
     * Data stored per entry: {lat, lng, accuracy, timestamp}
     * Key format: location_history:{user_id} (Redis sorted set, scored by timestamp)
     * The oldest member sets the whole key's maximum expiry, even without a scheduler.
     *
     * To query: Redis::zrangebyscore("location_history:{userId}", $from, $to)
     */
    private function storeLocationInRedis(User $user, float $lat, float $lng, ?float $accuracy, ?string $quality = null, ?string $rejected = null): void
    {
        try {
            $key = "location_history:{$user->id}";
            $timestamp = now()->timestamp;
            $this->retention->pruneHistory($key);

            // Deduplicate accepted readings: skip if last entry was <60s ago and <50m away
            // Always log rejected readings for debugging
            if (! $rejected) {
                $lastEntries = Redis::zrevrange($key, 0, 0);
                if (! empty($lastEntries)) {
                    $lastJson = $lastEntries[0];
                    $lastScore = (int) Redis::zscore($key, $lastJson);

                    if ($timestamp - $lastScore < 60) {
                        $last = json_decode($lastJson, true);
                        if ($last && ! ($last['rejected'] ?? false) && $this->distanceMeters($lat, $lng, (float) $last['lat'], (float) $last['lng']) < 50) {
                            return;
                        }
                    }
                }
            }

            $entry = json_encode(array_filter([
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'accuracy' => $accuracy ? round($accuracy) : null,
                'quality' => $quality,
                'rejected' => $rejected,
                'at' => now()->toIso8601String(),
            ], fn ($v) => $v !== null));

            Redis::zadd($key, $timestamp, $entry);

            $this->retention->pruneHistory($key);
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
