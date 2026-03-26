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
                    return collect($response->json('features', []))
                        ->map(fn (array $feature) => [
                            'name' => $feature['properties']['name'] ?? $feature['properties']['street'] ?? $query,
                            'street' => $feature['properties']['street'] ?? null,
                            'city' => $feature['properties']['city'] ?? $feature['properties']['county'] ?? null,
                            'lat' => $feature['geometry']['coordinates'][1] ?? 0.0,
                            'lng' => $feature['geometry']['coordinates'][0] ?? 0.0,
                        ])
                        ->all();
                }
            } catch (\Exception $e) {
                report($e);
            }

            return [];
        });
    }
}
