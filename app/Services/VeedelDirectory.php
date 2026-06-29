<?php

namespace App\Services;

use App\Enums\SpotCategory;
use Illuminate\Support\Facades\DB;

/**
 * Cologne's two-level browse geography — the 9 Bezirke and their Stadtteile
 * (Veedels) that actually hold leisure places. Shared by the Places page and
 * the Day Composer so both offer the same area picker, never drifting apart.
 */
class VeedelDirectory
{
    /**
     * The Bezirk rail: every district with at least one leisure place, busiest
     * first, the home Bezirk pinned to the front.
     *
     * @return list<array{name: string, count: int, photo_url: ?string}>
     */
    public function bezirkRail(?string $homeBezirk): array
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
     * Stadtteile (with places) per Bezirk, A→Z — the chip rows under each district.
     *
     * @return array<string, list<string>>
     */
    public function veedelsByBezirk(): array
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
