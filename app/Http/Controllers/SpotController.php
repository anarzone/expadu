<?php

namespace App\Http\Controllers;

use App\Enums\SpotCategory;
use App\Profile\ProfileEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SpotController extends Controller
{
    /**
     * Places — a list-first, Veedel-browsable discovery feed. The card
     * list itself is fetched client-side from GET /api/places; this only
     * seeds the page with the Veedel browse rail (home Veedel first) and
     * the default category filters from the URL.
     */
    public function index(Request $request, ProfileEngine $profiles): Response
    {
        $homeVeedel = $profiles->build($request->user())->veedel;

        return Inertia::render('places', [
            'homeVeedel' => $homeVeedel,
            'filters' => [
                'veedel' => $request->query('veedel') ?? $homeVeedel ?? 'all',
                'category' => $request->query('category'),
            ],
            // Browse rail: a short visual teaser (home Veedel + nearest).
            // Deferred so the page shell paints fast.
            'veedels' => Inertia::defer(fn () => $this->veedelRail($homeVeedel)),
            // Chips: the exhaustive list — every Veedel with places, A→Z.
            'allVeedels' => Inertia::defer(fn () => $this->allVeedels()),
        ]);
    }

    /** How many Veedel cards the browse rail shows (home + nearest). */
    private const RAIL_SIZE = 6;

    /**
     * Nearest-first so the rail reads as "your corner of Cologne", not an
     * alphabet or raw-count dump that surfaces the outskirts.
     *
     * @return list<array{name: string, count: int, photo_url: ?string}>
     */
    private function veedelRail(?string $homeVeedel): array
    {
        [$anchorLat, $anchorLng] = $this->railAnchor($homeVeedel);

        $rows = DB::table('spots')
            ->join('veedels', 'veedels.name', '=', 'spots.veedel')
            ->whereNotNull('spots.veedel')
            ->whereIn('spots.category', SpotCategory::placesFines())
            ->groupBy('spots.veedel', 'veedels.centroid_lat', 'veedels.centroid_lng')
            ->select('spots.veedel', DB::raw('count(*) as n'))
            ->selectRaw(
                '(6371 * acos(LEAST(1, cos(radians(?)) * cos(radians(veedels.centroid_lat)) * cos(radians(veedels.centroid_lng) - radians(?)) + sin(radians(?)) * sin(radians(veedels.centroid_lat))))) as anchor_km',
                [$anchorLat, $anchorLng, $anchorLat],
            )
            ->orderByRaw('anchor_km asc nulls last')
            ->limit(self::RAIL_SIZE)
            ->get();

        $rail = $rows->map(fn ($r) => [
            'name' => $r->veedel,
            'count' => (int) $r->n,
            'photo_url' => null,
        ])->all();

        // Pin the home Veedel first if it has places.
        if ($homeVeedel) {
            usort($rail, fn ($a, $b) => ($b['name'] === $homeVeedel) <=> ($a['name'] === $homeVeedel));
        }

        return $rail;
    }

    /**
     * @return list<string>
     */
    private function allVeedels(): array
    {
        return DB::table('spots')
            ->whereNotNull('veedel')
            ->whereIn('category', SpotCategory::placesFines())
            ->distinct()
            ->orderBy('veedel')
            ->pluck('veedel')
            ->all();
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function railAnchor(?string $homeVeedel): array
    {
        if ($homeVeedel) {
            $row = DB::table('veedels')
                ->where('name', $homeVeedel)
                ->whereNotNull('centroid_lat')
                ->first(['centroid_lat', 'centroid_lng']);
            if ($row) {
                return [(float) $row->centroid_lat, (float) $row->centroid_lng];
            }
        }

        return [50.9375, 6.9603]; // Cologne centre
    }
}
