<?php

namespace App\Services;

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFactRevision;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Cologne's two-level browse geography — the 9 Bezirke and their Stadtteile
 * (Veedels) that actually hold leisure places. Shared by the Places page and
 * the Day Composer so both offer the same area picker, never drifting apart.
 *
 * Both lists aggregate the whole catalogue, so they are cached per place-fact
 * revision: fresh for an hour, served stale (refreshed after the response) for
 * up to a day, and replaced as soon as a reviewed or imported fact bumps it.
 */
class VeedelDirectory
{
    private const TTL = [3600, 86400];

    /**
     * The Bezirk rail: every district with at least one leisure place, busiest
     * first, the home Bezirk pinned to the front.
     *
     * @return list<array{name: string, count: int, photo_url: ?string}>
     */
    public function bezirkRail(?string $homeBezirk): array
    {
        $rail = Cache::flexible($this->key('bezirk-rail'), self::TTL, fn (): array => app(DestinationGrouping::class)->general(Spot::query())
            ->join('veedels', 'veedels.name', '=', 'spots.veedel')
            ->whereIn('spots.category', SpotCategory::placesFines())
            ->groupBy('veedels.bezirk')
            ->select('veedels.bezirk as name', DB::raw('count(*) as n'))
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'count' => (int) $r->n,
                'photo_url' => null,
            ])
            ->all());

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
        return Cache::flexible($this->key('veedels-by-bezirk'), self::TTL, fn (): array => app(DestinationGrouping::class)->general(Spot::query())
            ->join('veedels', 'veedels.name', '=', 'spots.veedel')
            ->whereIn('spots.category', SpotCategory::placesFines())
            ->distinct()
            ->orderBy('veedels.name')
            ->get(['veedels.name', 'veedels.bezirk'])
            ->groupBy('bezirk')
            ->map(fn ($group) => $group->pluck('name')->values()->all())
            ->all());
    }

    private function key(string $list): string
    {
        return "places:{$list}:r".app(PlaceFactRevision::class)->current();
    }
}
