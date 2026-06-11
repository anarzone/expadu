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
}
