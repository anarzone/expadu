<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * Single source of truth for a user's current location.
 *
 * Priority:
 * 1. GPS coordinates from request params (lat/lng)
 * 2. Explicitly confirmed location ("I'm here" chip, 2h validity)
 * 3. Last known GPS ping from Redis (stored by EventTrackingService, 7-day TTL)
 * 4. Home place coordinates (user's first place by sort_order)
 * 5. Default: central Cologne (50.9375, 6.9603)
 *
 * Use this service instead of ad-hoc location resolution in controllers.
 */
class UserLocationService
{
    /**
     * Store an explicit "I'm here" confirmation, valid for two hours.
     */
    public function confirm(User $user, float $lat, float $lng, ?string $name = null): void
    {
        Redis::setex(
            "confirmed_location:{$user->id}",
            2 * 3600,
            json_encode([
                'lat' => $lat,
                'lng' => $lng,
                'name' => $name ?? ($this->reverseGeocode($lat, $lng) ?? 'Confirmed location'),
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array{lat: float, lng: float, name: string}|null
     */
    private function getConfirmedLocation(int $userId): ?array
    {
        try {
            $raw = Redis::get("confirmed_location:{$userId}");
            $data = $raw ? json_decode((string) $raw, true) : null;

            return is_array($data) && isset($data['lat'], $data['lng'])
                ? ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng'], 'name' => (string) ($data['name'] ?? '')]
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Resolve the user's best known location.
     *
     * @return array{lat: float, lng: float, source: string, name: string, address: string}
     */
    public function resolve(User $user, ?Request $request = null): array
    {
        // 1. GPS from request params (frontend sends lat/lng when GPS available)
        if ($request && $request->has('lat') && $request->has('lng')) {
            $lat = (float) $request->query('lat');
            $lng = (float) $request->query('lng');
            $address = $this->reverseGeocode($lat, $lng) ?? 'Cologne';

            return [
                'lat' => $lat,
                'lng' => $lng,
                'source' => 'gps',
                'name' => $address,
                'address' => $address,
            ];
        }

        // 2. Explicit "I'm here" confirmation beats any inferred source
        $confirmed = $this->getConfirmedLocation($user->id);
        if ($confirmed) {
            return [
                'lat' => $confirmed['lat'],
                'lng' => $confirmed['lng'],
                'source' => 'confirmed',
                'name' => $confirmed['name'],
                'address' => $confirmed['name'],
            ];
        }

        // 3. Last known GPS ping from Redis (within last 30 minutes)
        $redisPing = $this->getLastRedisPing($user->id);
        if ($redisPing) {
            $address = $this->reverseGeocode($redisPing['lat'], $redisPing['lng']) ?? 'Cologne';

            return [
                'lat' => $redisPing['lat'],
                'lng' => $redisPing['lng'],
                'source' => 'last_ping',
                'name' => $address,
                'address' => $address,
            ];
        }

        // 4. Home place
        $home = $user->places()->where('category', 'home')->first()
            ?? $user->places()->orderBy('sort_order')->first();

        if ($home && $home->lat && $home->lng) {
            return [
                'lat' => (float) $home->lat,
                'lng' => (float) $home->lng,
                'source' => 'home',
                'name' => $home->name ?? 'Home',
                'address' => $home->address ?? 'Cologne',
            ];
        }

        // 4. Default: central Cologne
        return [
            'lat' => 50.9375,
            'lng' => 6.9603,
            'source' => 'default',
            'name' => 'Cologne',
            'address' => 'Cologne, Germany',
        ];
    }

    /**
     * Reverse geocode coordinates to a street address using Photon API.
     * Cached for 1 hour to avoid excessive API calls.
     */
    private function reverseGeocode(float $lat, float $lng): ?string
    {
        // Round to ~100m precision for cache key (avoids cache misses for nearby pings)
        $cacheKey = 'reverse_geo:'.round($lat, 3).','.round($lng, 3);

        return cache()->remember($cacheKey, 3600, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(3)
                    ->get('https://photon.komoot.io/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                    ]);

                $feature = $response->json('features.0.properties');
                if (! $feature) {
                    return null;
                }

                $street = $feature['street'] ?? $feature['name'] ?? null;
                $house = $feature['housenumber'] ?? null;
                $district = $feature['district'] ?? $feature['locality'] ?? null;

                $parts = array_filter([$street, $house]);
                $address = implode(' ', $parts);

                if ($district && $address) {
                    return "{$address}, {$district}";
                }

                return $address ?: $district ?: ($feature['city'] ?? null);
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Get the last GPS ping from Redis within the last 30 minutes.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function getLastRedisPing(int $userId): ?array
    {
        $key = "location_history:{$userId}";
        $thirtyMinAgo = now()->subMinutes(30)->timestamp;

        try {
            // Get the most recent entry (highest score = most recent timestamp)
            $entries = Redis::zrevrangebyscore($key, '+inf', $thirtyMinAgo, ['LIMIT' => [0, 1]]);

            if (empty($entries)) {
                return null;
            }

            $data = json_decode($entries[0], true);
            if (! $data || ! isset($data['lat'], $data['lng'])) {
                return null;
            }

            return [
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
