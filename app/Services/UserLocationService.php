<?php

namespace App\Services;

use App\Enums\LocationSource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

/**
 * Single source of truth for a user's location.
 *
 * {@see self::context()} is the canonical resolver shared by the Places list
 * and take-me-there so they never disagree: explicit From (live coords /
 * picked area) → confirmed "I'm here" → recent ping → browsed-area centroid →
 * none. It never falls back to home/work or a guessed city centre.
 *
 * {@see self::resolve()} is the older, fuller-detail variant (carries a
 * reverse-geocoded name) still used by the Inertia share + timetable; it keeps
 * the legacy home/centre fallback and is being retired.
 */
class UserLocationService
{
    /**
     * Store an explicit "I'm here" confirmation, valid for two hours.
     */
    public function confirm(User $user, float $lat, float $lng, ?string $name = null): string
    {
        $resolved = $name ?? ($this->reverseGeocode($lat, $lng) ?? 'Confirmed location');

        Redis::setex(
            "confirmed_location:{$user->id}",
            2 * 3600,
            json_encode([
                'lat' => $lat,
                'lng' => $lng,
                'name' => $resolved,
            ], JSON_THROW_ON_ERROR),
        );

        // Remember the Veedel as the user's default area, so every surface
        // (Places browse, composer) falls back to where they last set
        // themselves instead of the frozen onboarding neighbourhood. The live
        // fix above still wins while it's fresh; this is the lasting fallback.
        $veedel = $this->veedelAt($lat, $lng);
        if ($veedel !== null && $veedel !== $user->veedel) {
            $user->update(['veedel' => $veedel]);
        }

        return $resolved;
    }

    /**
     * The single origin resolver for distance + routing, shared by the Places
     * list and take-me-there. Order:
     *
     * 1. An explicit live GPS fix in the request (lat/lng).
     * 2. An explicitly picked area ("From · {Veedel}", from_area).
     * 3. The "I'm here" confirmation.
     * 4. A recent GPS ping.
     * 5. The area currently being browsed ($fallbackArea) — distances relative
     *    to what you're looking at.
     * 6. None — no origin; the caller shows a "set your location" affordance
     *    and never measures from a guessed home/centre.
     */
    public function context(User $user, ?Request $request = null, ?string $fallbackArea = null): LocationContext
    {
        // input() (not query()) so an explicit From works from both the Places
        // GET query string and the composer's POST body.
        if ($request && is_numeric($request->input('lat')) && is_numeric($request->input('lng'))) {
            $lat = (float) $request->input('lat');
            $lng = (float) $request->input('lng');

            if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                // A picked saved place (Home/Work/…) sends its name so the From
                // control can label the origin; a raw GPS fix stays "Your location".
                $label = $request->input('from_label');
                $label = is_string($label) && $label !== '' ? $label : 'Your location';

                return new LocationContext($lat, $lng, LocationSource::Live, $label);
            }
        }

        // A picked saved place (Home/Work/pin) is referenced by id so its
        // coordinates never travel in the query string. Scoped to the user's
        // own places, so an arbitrary id can't measure from someone else's home.
        $placeId = $request?->input('from_place');
        if (is_numeric($placeId)) {
            $place = $user->places()->whereKey((int) $placeId)->first(['name', 'lat', 'lng']);
            if ($place && $place->lat !== null && $place->lng !== null) {
                return new LocationContext(
                    (float) $place->lat,
                    (float) $place->lng,
                    LocationSource::Live,
                    $place->name !== null && $place->name !== '' ? $place->name : 'Saved place',
                );
            }
        }

        $pickedArea = $request?->input('from_area');
        if (is_string($pickedArea) && $pickedArea !== '') {
            $centroid = $this->areaCentroid($pickedArea);
            if ($centroid) {
                return new LocationContext($centroid['lat'], $centroid['lng'], LocationSource::Area, $pickedArea);
            }
        }

        $confirmed = $this->getConfirmedLocation($user->id);
        if ($confirmed) {
            return new LocationContext($confirmed['lat'], $confirmed['lng'], LocationSource::Confirmed, $confirmed['name'] ?: 'Your location');
        }

        $ping = $this->getLastRedisPing($user->id);
        if ($ping) {
            return new LocationContext($ping['lat'], $ping['lng'], LocationSource::Ping, 'Your location');
        }

        if ($fallbackArea !== null && $fallbackArea !== '') {
            $centroid = $this->areaCentroid($fallbackArea);
            if ($centroid) {
                return new LocationContext($centroid['lat'], $centroid['lng'], LocationSource::Area, $fallbackArea);
            }
        }

        return new LocationContext(null, null, LocationSource::None);
    }

    /**
     * The stored centroid of a Veedel, if we have one.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function areaCentroid(string $veedel): ?array
    {
        $row = DB::table('veedels')
            ->where('name', $veedel)
            ->whereNotNull('centroid_lat')
            ->first(['centroid_lat', 'centroid_lng']);

        return $row
            ? ['lat' => (float) $row->centroid_lat, 'lng' => (float) $row->centroid_lng]
            : null;
    }

    /**
     * The Veedel a coordinate falls in: polygon containment when boundaries are
     * loaded, else the nearest centroid (neighbourhood granularity is enough).
     * Lets the composer derive the plan's default search area from wherever the
     * user is actually starting, so the area follows the origin instead of a
     * fixed home. Null when no Veedels are known.
     */
    public function veedelAt(float $lat, float $lng): ?string
    {
        $byPolygon = DB::selectOne(
            'SELECT name FROM veedels
             WHERE boundary IS NOT NULL
               AND ST_Contains(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326))
             LIMIT 1',
            [$lng, $lat],
        );

        if ($byPolygon !== null) {
            return (string) $byPolygon->name;
        }

        // No boundaries loaded (or the point sits outside every polygon): fall
        // back to the nearest centroid, longitude scaled by latitude so the
        // comparison is roughly metric this far north.
        $nearest = DB::table('veedels')
            ->whereNotNull('centroid_lat')
            ->orderByRaw('(centroid_lat - ?)^2 + ((centroid_lng - ?) * cos(radians(?)))^2', [$lat, $lng, $lat])
            ->value('name');

        return $nearest !== null ? (string) $nearest : null;
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
     * Get the last *usable* GPS ping from Redis within the last 30 minutes.
     *
     * The history intentionally also stores rejected readings (poor accuracy or
     * impossible speed jumps — common on weak networks; see
     * EventTrackingService::storeLocationInRedis) for debugging. Those must
     * never anchor a board or distance: a single bad fix would drag the
     * departures board to the wrong station. So walk newest-first and return
     * the first *accepted* fix, skipping any tagged `rejected`.
     *
     * @return array{lat: float, lng: float}|null
     */
    private function getLastRedisPing(int $userId): ?array
    {
        $key = "location_history:{$userId}";
        $thirtyMinAgo = now()->subMinutes(30)->timestamp;

        try {
            // Newest first. Scan a small window so a burst of rejected weak-signal
            // readings can't hide the last good fix behind them.
            $entries = Redis::zrevrangebyscore($key, '+inf', $thirtyMinAgo, ['LIMIT' => [0, 25]]);

            foreach ($entries as $raw) {
                $data = json_decode((string) $raw, true);

                if (! is_array($data) || ! isset($data['lat'], $data['lng'])) {
                    continue;
                }

                if (! empty($data['rejected'])) {
                    continue;
                }

                return [
                    'lat' => (float) $data['lat'],
                    'lng' => (float) $data['lng'],
                ];
            }

            return null;
        } catch (\Throwable) {
            return null;
        }
    }
}
