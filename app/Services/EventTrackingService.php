<?php

namespace App\Services;

use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;

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

        // Auto-detect nearby spots from GPS location
        if ($eventType === 'location_ping' && isset($payload['lat'], $payload['lng'])) {
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

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
