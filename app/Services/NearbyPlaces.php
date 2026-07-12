<?php

namespace App\Services;

use App\Models\Spot;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * The single source of truth for "places near a point".
 *
 * Every surface that ranks or filters spots by proximity — the home discovery
 * rails, the Day Composer's candidate pool, the Places list's "near me" and
 * "{Veedel} & nearby" rings — used to inline the SAME great-circle SQL and its
 * own copy of the "widen until we have enough" trick. Small differences in
 * origin or radius between those copies made one screen say "in your area" while
 * another said "an hour away". This centralises the distance maths and the
 * fetch policies so proximity is defined ONCE; callers pass params and keep only
 * their own selection/mapping on top.
 */
class NearbyPlaces
{
    /**
     * Great-circle distance in kilometres for a `spots` row, as a SQL fragment.
     * Bindings are three positional params — use {@see bindings()} to supply
     * them in the right order: origin-lat, origin-lng, origin-lat.
     */
    public const DISTANCE_KM_SQL = '6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))';

    /** Effectively "show all" — returned when fewer than the wanted count exist anywhere. */
    private const SHOW_ALL_KM = 100.0;

    /**
     * Positional bindings for {@see DISTANCE_KM_SQL}: (lat, lng, lat).
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public static function bindings(float $lat, float $lng): array
    {
        return [$lat, $lng, $lat];
    }

    /**
     * Great-circle distance in kilometres between two points, in PHP — the
     * in-memory twin of {@see DISTANCE_KM_SQL} for callers that already hold the
     * rows (rail card stamping, home-radius bounding).
     */
    public static function km(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * asin(min(1.0, sqrt($a)));
    }

    /**
     * Order a spots query nearest-first from an origin (ties broken by id so the
     * order is deterministic). Works on Eloquent and base query builders alike.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function orderByDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query->orderByRaw(self::DISTANCE_KM_SQL.', id', self::bindings($lat, $lng));
    }

    /**
     * Restrict a spots query to rows within `$km` of an origin.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    public function withinKm(Builder $query, float $lat, float $lng, float $km): Builder
    {
        return $query->whereRaw(self::DISTANCE_KM_SQL.' <= ?', [...self::bindings($lat, $lng), $km]);
    }

    /**
     * The tightest radius that still includes at least `$minResults` rows of
     * `$base` — never smaller than `$startKm` — so a sparse edge of the city
     * yields a full-enough list without over-widening. Returns the exact
     * distance of the Nth-nearest match (one query, +50m so that row survives a
     * WHERE re-check under float rounding); {@see SHOW_ALL_KM} when fewer than
     * `$minResults` exist anywhere. `$base` is cloned, so the caller's builder is
     * untouched.
     */
    public function radiusForAtLeast(Builder $base, float $lat, float $lng, float $startKm, int $minResults = 3): float
    {
        $nth = (clone $base)
            ->selectRaw('('.self::DISTANCE_KM_SQL.') as d', self::bindings($lat, $lng))
            ->reorder('d')
            ->offset($minResults - 1)
            ->limit(1)
            ->first();

        if ($nth === null || $nth->d === null) {
            return self::SHOW_ALL_KM;
        }

        return max($startKm, (float) $nth->d + 0.05);
    }

    /**
     * The `$limit` spots nearest an origin, nearest-first — the browse/rank pool
     * the home rails and composer draw from. Optionally narrowed to
     * `$categories` and to a subset of `$columns` (lighter rows for caching).
     *
     * @param  list<string>|null  $categories
     * @param  list<string>|null  $columns
     * @return Collection<int, Spot>
     */
    public function nearest(float $lat, float $lng, int $limit, ?array $categories = null, ?array $columns = null): Collection
    {
        $query = Spot::query()
            ->recommendationEligible()
            ->when($columns !== null, fn ($q) => $q->select($columns))
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->when($categories !== null, fn ($q) => $q->whereIn('category', $categories));

        return $this->orderByDistance($query, $lat, $lng)
            ->limit($limit)
            ->get();
    }
}
