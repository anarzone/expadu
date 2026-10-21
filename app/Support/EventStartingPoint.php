<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The Events page's starting point, as its URL may carry it. A URL only
 * names a public point (a district, a stop or a venue, by its own name) or
 * a saved place's key (`home`, `work`), which resolves to the signed-in
 * person's own place. Anything else — a typed address above all — never
 * resolves, so the page keeps it in its state and out of URLs, history
 * and server logs.
 */
class EventStartingPoint
{
    /** Saved places a URL may name, with the label it shows. */
    public const SAVED = ['home' => 'Home', 'work' => 'Work'];

    /**
     * @return array{key: string, name: string, lat: float, lng: float}|null
     */
    public function resolve(?string $from, ?User $user): ?array
    {
        if ($from === null || trim($from) === '') {
            return null;
        }

        return array_key_exists($from, self::SAVED)
            ? $this->saved($from, $user)
            : $this->public($from);
    }

    /**
     * The person's own saved places, for the page's starting point choices.
     *
     * @return list<array{key: string, name: string, lat: float, lng: float}>
     */
    public function savedPlaces(?User $user): array
    {
        return array_values(array_filter(array_map(
            fn (string $key) => $this->saved($key, $user),
            array_keys(self::SAVED),
        )));
    }

    /**
     * @return array{key: string, name: string, lat: float, lng: float}|null
     */
    private function saved(string $key, ?User $user): ?array
    {
        $place = $user?->places()
            ->where('category', $key)
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('sort_order')
            ->first(['lat', 'lng']);

        return $place === null ? null : [
            'key' => $key,
            'name' => self::SAVED[$key],
            'lat' => (float) $place->lat,
            'lng' => (float) $place->lng,
        ];
    }

    /**
     * A district, stop or venue named exactly (case aside).
     *
     * @return array{key: string, name: string, lat: float, lng: float}|null
     */
    public function public(string $name): ?array
    {
        return $this->publicMany([$name])[mb_strtolower(trim($name))] ?? null;
    }

    /**
     * The names that are districts, stops or venues, keyed by the name in
     * lower case. A district wins over a stop, a stop over a venue.
     *
     * @param  list<string>  $names
     * @return array<string, array{key: string, name: string, lat: float, lng: float}>
     */
    public function publicMany(array $names): array
    {
        $names = array_values(array_unique(array_filter(
            array_map(fn (string $name) => mb_strtolower(trim($name)), $names),
            fn (string $name) => mb_strlen($name) >= 2 && mb_strlen($name) <= 120,
        )));

        if ($names === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($names), '?'));
        $found = [];
        $sources = [
            DB::table('venues')
                ->whereRaw("lower(name) in ({$in})", $names)
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->get(['name', 'lat', 'lng']),
            DB::table('gtfs_stops')
                ->whereRaw("lower(stop_name) in ({$in})", $names)
                ->whereIn('location_type', [0, 1])
                ->orderBy('location_type')
                ->get(['stop_name as name', 'stop_lat as lat', 'stop_lng as lng']),
            DB::table('veedels')
                ->whereRaw("lower(name) in ({$in})", $names)
                ->whereNotNull('centroid_lat')
                ->get(['name', 'centroid_lat as lat', 'centroid_lng as lng']),
        ];

        // Later sources overwrite earlier ones; a station (location_type 1)
        // overwrites its platforms.
        foreach ($sources as $rows) {
            foreach ($rows as $row) {
                $found[mb_strtolower($row->name)] = [
                    'key' => $row->name,
                    'name' => $row->name,
                    'lat' => (float) $row->lat,
                    'lng' => (float) $row->lng,
                ];
            }
        }

        return $found;
    }
}
