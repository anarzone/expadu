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
     * Places — a list-first discovery feed with a two-level geography:
     * the rail shows Cologne's 9 Bezirke (big cards), the chips show the
     * Stadtteile (Veedels) of the selected Bezirk. The card list itself
     * is fetched client-side from GET /api/places.
     */
    public function index(Request $request, ProfileEngine $profiles): Response
    {
        $homeVeedel = $profiles->build($request->user())->veedel;
        $homeBezirk = $homeVeedel
            ? DB::table('veedels')->where('name', $homeVeedel)->value('bezirk')
            : null;

        // No geography in the URL → default to the user's home corner.
        if ($request->filled('veedel') || $request->filled('bezirk')) {
            $veedel = $request->query('veedel');
            $bezirk = $request->query('bezirk')
                ?? ($veedel ? DB::table('veedels')->where('name', $veedel)->value('bezirk') : null)
                ?? 'all';
        } else {
            $veedel = $homeVeedel;
            $bezirk = $homeBezirk ?? 'all';
        }

        return Inertia::render('places', [
            'homeVeedel' => $homeVeedel,
            'homeBezirk' => $homeBezirk,
            'filters' => [
                'bezirk' => $bezirk,
                'veedel' => $veedel,
                'category' => $request->query('category'),
            ],
            // Deferred so the page shell paints fast.
            'bezirke' => Inertia::defer(fn () => $this->bezirkRail($homeBezirk)),
            'veedelsByBezirk' => Inertia::defer(fn () => $this->veedelsByBezirk()),
        ]);
    }

    /**
     * The browse rail: every Bezirk with at least one leisure place —
     * at most 9 cards, home Bezirk pinned first, then by place count.
     *
     * @return list<array{name: string, count: int, photo_url: ?string}>
     */
    private function bezirkRail(?string $homeBezirk): array
    {
        $rows = DB::table('spots')
            ->join('veedels', 'veedels.name', '=', 'spots.veedel')
            ->whereIn('spots.category', SpotCategory::placesFines())
            ->groupBy('veedels.bezirk')
            ->select('veedels.bezirk as name', DB::raw('count(*) as n'))
            ->orderByDesc('n')
            ->get();

        $rail = $rows->map(fn ($r) => [
            'name' => $r->name,
            'count' => (int) $r->n,
            'photo_url' => null,
        ])->all();

        if ($homeBezirk) {
            usort($rail, fn ($a, $b) => ($b['name'] === $homeBezirk) <=> ($a['name'] === $homeBezirk));
        }

        return $rail;
    }

    /**
     * Stadtteile (with places) per Bezirk, A→Z — the chip rows.
     *
     * @return array<string, list<string>>
     */
    private function veedelsByBezirk(): array
    {
        return DB::table('spots')
            ->join('veedels', 'veedels.name', '=', 'spots.veedel')
            ->whereIn('spots.category', SpotCategory::placesFines())
            ->distinct()
            ->orderBy('veedels.name')
            ->get(['veedels.name', 'veedels.bezirk'])
            ->groupBy('bezirk')
            ->map(fn ($group) => $group->pluck('name')->values()->all())
            ->all();
    }
}
