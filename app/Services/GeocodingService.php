<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeocodingService
{
    /**
     * Search for places using the Photon geocoding API.
     *
     * @return array<int, array{name: string, street: string|null, city: string|null, lat: float, lng: float}>
     */
    public function search(string $query, float $lat = 50.9375, float $lng = 6.9603): array
    {
        $cacheKey = 'geocode_'.md5("{$query}_{$lat}_{$lng}");

        return Cache::remember($cacheKey, 300, function () use ($query, $lat, $lng) {
            try {
                $response = Http::timeout(3)
                    ->get('https://photon.komoot.io/api/', [
                        'q' => $query,
                        'lat' => $lat,
                        'lon' => $lng,
                        'limit' => 5,
                        'lang' => 'en',
                    ]);

                if ($response->successful()) {
                    return $this->mapFeatures($response->json('features', []), $query);
                }
            } catch (\Exception $e) {
                report($e);
            }

            return [];
        });
    }

    /**
     * Map Photon features to our result shape, collapsing rows that render
     * identically. Photon often returns several near-duplicate features for one
     * query (e.g. "Neumarkt · Köln" ×4); we keep the best-ranked of each so the
     * suggestion list shows distinct, choosable options.
     *
     * @param  array<int, array<string, mixed>>  $features
     * @return array<int, array{name: string, street: string|null, city: string|null, lat: float, lng: float}>
     */
    public function mapFeatures(array $features, string $query = ''): array
    {
        return collect($features)
            ->map(function (array $feature) use ($query) {
                $props = $feature['properties'] ?? [];
                $street = $props['street'] ?? null;
                $houseNumber = $props['housenumber'] ?? null;
                $name = $props['name'] ?? $street ?? $query;

                // Build full address with house number
                $fullStreet = $street;
                if ($street && $houseNumber) {
                    $fullStreet = "{$street} {$houseNumber}";
                }

                // Use full street as name if the result is a house/address
                $type = $props['type'] ?? $props['osm_value'] ?? '';
                if (in_array($type, ['house', 'address', 'residential']) && $fullStreet) {
                    $name = $fullStreet;
                }

                return [
                    'name' => $name,
                    'street' => $fullStreet,
                    'city' => $props['city'] ?? $props['county'] ?? null,
                    'lat' => $feature['geometry']['coordinates'][1] ?? 0.0,
                    'lng' => $feature['geometry']['coordinates'][0] ?? 0.0,
                ];
            })
            ->unique(fn (array $result) => "{$result['name']}|{$result['street']}|{$result['city']}")
            ->values()
            ->all();
    }
}
