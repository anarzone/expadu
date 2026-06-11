<?php

namespace App\Http\Controllers\Api;

use App\Enums\SpotCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlaceResource;
use App\Models\Spot;
use App\Profile\ProfileEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/places?veedel=&category=&page=
 *
 * Lists physical-leisure places from our own seeded DB — no live
 * third-party calls. Distance is computed from the user's anchor
 * (home place → Veedel centroid → Cologne centre); transit_hint is the
 * nearest GTFS stop (our static data, not a routing API).
 */
class PlacesController extends Controller
{
    private const PER_PAGE = 20;

    private const COARSE = ['park', 'pitch', 'court', 'swimming', 'playground', 'dog_park'];

    public function __invoke(Request $request, ProfileEngine $profiles): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'veedel' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'in:'.implode(',', self::COARSE)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        [$anchorLat, $anchorLng] = $this->anchor($request, $profiles);

        $query = Spot::query()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereIn('category', SpotCategory::placesFines());

        if (! empty($validated['category'])) {
            $query->whereIn('category', SpotCategory::finesForCoarse($validated['category']));
        }

        if (! empty($validated['veedel']) && $validated['veedel'] !== 'all') {
            $query->where('veedel', $validated['veedel']);
        }

        $page = (int) ($validated['page'] ?? 1);

        $paginator = $query
            ->select('*')
            ->selectRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(lat)) * cos(radians(lng) - radians(?)) + sin(radians(?)) * sin(radians(lat))))) as distance_km',
                [$anchorLat, $anchorLng, $anchorLat],
            )
            ->orderBy('distance_km')
            ->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $paginator->getCollection()->transform(function (Spot $spot) {
            $spot->transit_hint = $this->nearestStopHint((float) $spot->lat, (float) $spot->lng);

            return $spot;
        });

        return PlaceResource::collection($paginator);
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function anchor(Request $request, ProfileEngine $profiles): array
    {
        $user = $request->user();

        $home = $user->places()->where('category', 'home')->first();
        if ($home?->lat && $home?->lng) {
            return [(float) $home->lat, (float) $home->lng];
        }

        $veedel = $profiles->build($user)->veedel;
        if ($veedel) {
            $row = DB::table('veedels')
                ->where('name', $veedel)
                ->whereNotNull('centroid_lat')
                ->first(['centroid_lat', 'centroid_lng']);
            if ($row) {
                return [(float) $row->centroid_lat, (float) $row->centroid_lng];
            }
        }

        return [50.9375, 6.9603]; // Cologne centre
    }

    /**
     * Nearest GTFS stop within ~800m, as a plain hint string.
     */
    private function nearestStopHint(float $lat, float $lng): ?string
    {
        try {
            $delta = 0.0075; // ~800m latitude box

            $stop = DB::table('gtfs_stops')
                ->where('location_type', 0)
                ->whereBetween('stop_lat', [$lat - $delta, $lat + $delta])
                ->whereBetween('stop_lng', [$lng - $delta, $lng + $delta])
                ->selectRaw(
                    'stop_name, (6371000 * acos(LEAST(1, cos(radians(?)) * cos(radians(stop_lat)) * cos(radians(stop_lng) - radians(?)) + sin(radians(?)) * sin(radians(stop_lat))))) as meters',
                    [$lat, $lng, $lat],
                )
                ->orderBy('meters')
                ->first();

            if (! $stop) {
                return null;
            }

            $walkMin = max(1, (int) round(($stop->meters / 4.5 / 1000) * 60));

            return "Nearest stop: {$stop->stop_name} (~{$walkMin} min walk)";
        } catch (\Throwable) {
            return null;
        }
    }
}
