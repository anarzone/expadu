<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ReverseGeocodeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => ['required', 'numeric'],
            'lng' => ['required', 'numeric'],
        ]);

        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        $cacheKey = 'reverse_geo:'.round($lat, 3).','.round($lng, 3);

        $address = cache()->remember($cacheKey, 3600, function () use ($lat, $lng) {
            try {
                $response = Http::timeout(3)->get('https://photon.komoot.io/reverse', [
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
                $addr = implode(' ', $parts);

                if ($district && $addr) {
                    return "{$addr}, {$district}";
                }

                return $addr ?: $district ?: ($feature['city'] ?? null);
            } catch (\Throwable) {
                return null;
            }
        });

        return response()->json(['address' => $address]);
    }
}
