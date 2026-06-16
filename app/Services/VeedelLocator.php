<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Keeps a user's home Veedel ("Around …") current from background GPS pings —
 * the source of truth for which neighbourhood the discovery feed and composer
 * orient around.
 *
 * The Köln Stadtteil polygons were never imported (every `veedels.boundary` is
 * null), so containment falls back to the nearest centroid — the Voronoi cell
 * around each Stadtteil. To respect the owner's rule ("only update within a
 * recommended radius, not very small movements") a minimum-displacement gate
 * sits in front of every write: the Veedel is only re-evaluated once the user
 * has moved at least {@see self::MIN_MOVE_M} from the last anchor, so GPS jitter
 * and ordinary moving-about never churn the feed.
 */
class VeedelLocator
{
    /**
     * Re-evaluate the Veedel only once the user has moved at least this far from
     * the last anchor. Below it, the ping is treated as jitter / staying put.
     */
    private const MIN_MOVE_M = 500;

    /** A ping further than this from every centroid is "outside Köln" — leave the Veedel untouched. */
    private const MAX_VEEDEL_KM = 6.0;

    /** How long an anchor lives in Redis before the next ping re-evaluates from scratch. */
    private const ANCHOR_TTL = 604800; // 7 days

    /**
     * Resolve the nearest Veedel name for a coordinate, or null when nothing is
     * within {@see self::MAX_VEEDEL_KM}.
     */
    public function nearestVeedel(float $lat, float $lng): ?string
    {
        // Haversine distance (metres) from the ping to each centroid; the three
        // bindings are lat, lng, lat — matching the placeholders left-to-right.
        $row = DB::table('veedels')
            ->whereNotNull('centroid_lat')
            ->whereNotNull('centroid_lng')
            ->selectRaw(
                'name, 6371000 * acos(LEAST(1, cos(radians(?)) * cos(radians(centroid_lat)) * cos(radians(centroid_lng) - radians(?)) + sin(radians(?)) * sin(radians(centroid_lat)))) AS distance_m',
                [$lat, $lng, $lat],
            )
            ->orderBy('distance_m')
            ->first();

        if ($row === null || (float) $row->distance_m > self::MAX_VEEDEL_KM * 1000) {
            return null;
        }

        return $row->name;
    }

    /**
     * Update the user's Veedel from a background GPS ping, but only on a genuine
     * relocation — never on jitter or small movements.
     *
     * Returns the new Veedel name when it changed, otherwise null.
     */
    public function syncFromPing(User $user, float $lat, float $lng): ?string
    {
        if (! $user->setting('share_location')) {
            return null;
        }

        $anchorKey = "veedel_anchor:{$user->id}";
        $anchor = $this->readAnchor($anchorKey);

        // Small movement from the last anchor → jitter / staying put, do nothing.
        if ($anchor !== null && $this->distanceMeters($lat, $lng, $anchor['lat'], $anchor['lng']) < self::MIN_MOVE_M) {
            return null;
        }

        $nearest = $this->nearestVeedel($lat, $lng);

        if ($nearest === null) {
            return null; // outside Köln — keep whatever they have
        }

        // Re-anchor here so the next displacement is measured from the new spot,
        // even when the Veedel itself does not change.
        $this->writeAnchor($anchorKey, $lat, $lng, $nearest);

        if ($user->veedel === $nearest) {
            return null;
        }

        $user->update(['veedel' => $nearest]);

        return $nearest;
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function readAnchor(string $key): ?array
    {
        try {
            $raw = Redis::get($key);

            if (! $raw) {
                return null;
            }

            $data = json_decode($raw, true);

            if (! isset($data['lat'], $data['lng'])) {
                return null;
            }

            return ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']];
        } catch (\Throwable) {
            return null;
        }
    }

    private function writeAnchor(string $key, float $lat, float $lng, string $veedel): void
    {
        try {
            Redis::setex($key, self::ANCHOR_TTL, json_encode([
                'lat' => round($lat, 6),
                'lng' => round($lng, 6),
                'veedel' => $veedel,
            ]));
        } catch (\Throwable) {
            // Redis unavailable — skip anchoring; the next ping re-evaluates.
        }
    }

    private function distanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
