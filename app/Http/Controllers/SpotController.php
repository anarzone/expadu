<?php

namespace App\Http\Controllers;

use App\Enums\SpotCategory;
use App\Models\Spot;
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
            // Browse rail: each Veedel with at least one leisure place, the
            // home Veedel pinned first. Deferred so the page shell paints fast.
            'veedels' => Inertia::defer(fn () => $this->veedelRail($homeVeedel)),
        ]);
    }

    /**
     * @return list<array{name: string, count: int, photo_url: ?string}>
     */
    private function veedelRail(?string $homeVeedel): array
    {
        $rows = DB::table('spots')
            ->select('veedel', DB::raw('count(*) as n'))
            ->whereNotNull('veedel')
            ->whereIn('category', SpotCategory::placesFines())
            ->groupBy('veedel')
            ->orderByDesc('n')
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

    public function legacyIndex(Request $request, ProfileEngine $profiles): Response
    {
        $userLat = $request->query('lat');
        $userLng = $request->query('lng');

        if (! $userLat || ! $userLng) {
            $home = $request->user()->places()->orderBy('sort_order')->first();
            if ($home?->lat && $home?->lng) {
                $userLat = $home->lat;
                $userLng = $home->lng;
            }
        }

        $profile = $profiles->build($request->user());

        // Veedel is the primary navigation: default to the user's home
        // Veedel, 'all' lifts the filter entirely.
        $veedel = $request->query('veedel') ?? $profile->veedel ?? 'all';
        $category = $request->query('category');
        $noise = $request->query('noise_level');

        return Inertia::render('explore', [
            'spots' => Inertia::defer(function () use ($category, $noise, $veedel, $userLat, $userLng) {
                $query = Spot::query();
                if ($veedel !== 'all') {
                    $query->where('veedel', $veedel);
                }
                if ($category) {
                    $query->where('category', $category);
                }
                if ($noise) {
                    $query->where('noise_level', $noise);
                }

                if ($userLat && $userLng) {
                    $query->nearby((float) $userLat, (float) $userLng);
                } else {
                    $query->orderByDesc('rating');
                }

                return $query->paginate(20);
            }),
            'filters' => [
                'veedel' => $veedel,
                'category' => $category,
                'noise_level' => $noise,
                'sort' => $request->query('sort', $userLat ? 'distance' : 'rating'),
            ],
            // Pills: home Veedel first, then the Veedels with the most
            // content, capped so the row stays scrollable.
            'veedelOptions' => Inertia::defer(fn () => collect([$profile->veedel])
                ->filter()
                ->merge(
                    DB::table('spots')
                        ->select('veedel', DB::raw('count(*) as n'))
                        ->whereNotNull('veedel')
                        ->groupBy('veedel')
                        ->orderByDesc('n')
                        ->limit(10)
                        ->pluck('veedel'),
                )
                ->unique()
                ->take(8)
                ->values()
                ->all()),
            'personalPlaces' => $request->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }

    public function show(Spot $spot): Response
    {
        return Inertia::render('explore', [
            'spot' => $spot,
            'spots' => Inertia::defer(fn () => Spot::orderByDesc('rating')->paginate(50)),
            'filters' => [],
            'personalPlaces' => request()->user()->places()
                ->select('id', 'emoji', 'name', 'address', 'lat', 'lng')
                ->get(),
        ]);
    }
}
