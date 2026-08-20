<?php

namespace App\Media;

use Illuminate\Support\Facades\Http;

/**
 * Street-level photos from Mapillary (CC BY-SA 4.0, free for commercial use).
 *
 * Unlike Commons — where a human deliberately photographed and named a place —
 * Mapillary is a firehose of dashcam and phone frames captured while passing
 * by. Proximity alone therefore proves nothing: the nearest frame to a
 * playground is usually the street outside it, pointing the other way.
 *
 * So the gate here is geometric rather than lexical: the camera must have been
 * FACING the spot. We compare each frame's compass angle against the bearing
 * from the camera to the spot and keep the closest frame that was actually
 * looking at it. Same bias as the Commons resolver — correct or nothing.
 */
class MapillaryPhotoResolver
{
    private const ENDPOINT = 'https://graph.mapillary.com/images';

    /** Panoramas are 360° so "facing" is meaningless — they always contain the spot. */
    private const FIELDS = 'id,thumb_1024_url,thumb_2048_url,compass_angle,computed_compass_angle,geometry,captured_at,creator,is_pano,quality_score';

    public function configured(): bool
    {
        return is_string(config('media.mapillary.token')) && trim((string) config('media.mapillary.token')) !== '';
    }

    /**
     * The best street-level frame that was pointing at this coordinate, or null.
     *
     * @param  callable(string): void|null  $onError
     * @return array{remote_url: string, source_page_url: string, author: ?string, attribution: string, license_code: string, license_url: string, mime_type: ?string, width: ?int, height: ?int, checksum: ?string, rights_status: string, health_status: string, provider_asset_id: string}|null
     */
    public function resolve(float $lat, float $lng, ?callable $onError = null): ?array
    {
        if (! $this->configured()) {
            return null;
        }

        try {
            $images = Http::withUserAgent((string) config('media.user_agent'))
                ->timeout(20)
                ->get(self::ENDPOINT, [
                    'access_token' => (string) config('media.mapillary.token'),
                    'lat' => $lat,
                    'lng' => $lng,
                    'radius' => min(50, (int) config('media.mapillary.radius_metres', 30)),
                    'limit' => 25,
                    'fields' => self::FIELDS,
                ])
                ->json('data', []);
        } catch (\Exception $e) {
            if ($onError !== null) {
                $onError($e->getMessage());
            }

            return null;
        }

        $best = $this->pickFacingImage($images ?? [], $lat, $lng);

        return $best === null ? null : $this->describe($best);
    }

    /**
     * Closest frame whose camera was aimed at the spot. Panoramas skip the
     * bearing test because they capture every direction at once.
     *
     * @param  array<int, array<string, mixed>>  $images
     * @return array<string, mixed>|null
     */
    public function pickFacingImage(array $images, float $lat, float $lng): ?array
    {
        $maxOffset = (float) config('media.mapillary.max_bearing_offset_degrees', 40);
        $best = null;
        $bestDistance = PHP_FLOAT_MAX;

        foreach ($images as $image) {
            $coordinates = $image['geometry']['coordinates'] ?? null;
            if (! is_array($coordinates) || count($coordinates) < 2) {
                continue;
            }

            $cameraLng = (float) $coordinates[0];
            $cameraLat = (float) $coordinates[1];
            $distance = $this->distanceMetres($cameraLat, $cameraLng, $lat, $lng);

            // A frame taken exactly on the spot has no meaningful bearing.
            if ($distance > 1.0 && ! ($image['is_pano'] ?? false)) {
                $heading = $image['computed_compass_angle'] ?? $image['compass_angle'] ?? null;
                if (! is_numeric($heading)) {
                    continue;
                }

                $bearing = $this->bearingDegrees($cameraLat, $cameraLng, $lat, $lng);
                if ($this->angleDifference((float) $heading, $bearing) > $maxOffset) {
                    continue; // camera was looking somewhere else
                }
            }

            if ($distance < $bestDistance && ($image['thumb_2048_url'] ?? $image['thumb_1024_url'] ?? null) !== null) {
                $best = $image;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $image
     * @return array<string, mixed>
     */
    private function describe(array $image): array
    {
        $id = (string) ($image['id'] ?? '');
        $author = $image['creator']['username'] ?? null;
        $author = is_string($author) && trim($author) !== '' ? trim($author) : null;

        $attribution = trim(implode(' · ', array_filter([
            $author,
            'CC BY-SA 4.0',
            'Mapillary',
        ])));

        return [
            'provider_asset_id' => $id,
            'remote_url' => (string) ($image['thumb_2048_url'] ?? $image['thumb_1024_url']),
            'source_page_url' => 'https://www.mapillary.com/app/?pKey='.rawurlencode($id),
            'author' => $author,
            'attribution' => $attribution,
            'license_code' => 'CC BY-SA 4.0',
            'license_url' => 'https://creativecommons.org/licenses/by-sa/4.0/',
            'mime_type' => 'image/jpeg',
            'width' => null,
            'height' => null,
            'checksum' => null,
            // Every Mapillary frame carries the same known open licence and a
            // resolvable source page, so rights are settled at capture time.
            // Health still has to be proven by actually fetching the bytes —
            // the CDN URLs are signed and can expire.
            'rights_status' => $author === null ? 'pending' : 'approved',
            'health_status' => 'pending',
        ];
    }

    public function distanceMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Compass bearing (0–360°, north = 0) from one coordinate to another. */
    public function bearingDegrees(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $fromLatRad = deg2rad($fromLat);
        $toLatRad = deg2rad($toLat);
        $deltaLng = deg2rad($toLng - $fromLng);

        $y = sin($deltaLng) * cos($toLatRad);
        $x = cos($fromLatRad) * sin($toLatRad) - sin($fromLatRad) * cos($toLatRad) * cos($deltaLng);

        return fmod(rad2deg(atan2($y, $x)) + 360, 360);
    }

    /** Smallest angle between two compass headings (0–180°). */
    public function angleDifference(float $a, float $b): float
    {
        $difference = fmod(abs($a - $b), 360);

        return $difference > 180 ? 360 - $difference : $difference;
    }
}
