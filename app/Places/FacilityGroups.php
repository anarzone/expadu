<?php

namespace App\Places;

use App\Models\Spot;
use Illuminate\Support\Facades\DB;

/**
 * A park with a football pitch, tennis courts and a playground is one place
 * to go, not five unnamed rows. Facilities inside a named park or sports
 * ground (parks:import-areas sets parent_spot_id from OSM geometry) are
 * answered as that park, with what it has: "Football pitch · 2 tennis
 * courts · Playground". A named facility (a club's ground) stays its own.
 */
class FacilityGroups
{
    /** Kinds that are part of a place rather than a place to go on their own. */
    public const FACILITIES = ['pitch', 'tennis', 'basketball', 'table_tennis', 'boules', 'skatepark', 'playground', 'dog_park', 'picnic', 'bbq'];

    /**
     * Each facility's park, for the ids given that have one: [facility id => park id].
     * Only unnamed facilities whose park is active.
     *
     * @param  list<int>  $spotIds
     * @return array<int, int>
     */
    public function parents(array $spotIds): array
    {
        if ($spotIds === []) {
            return [];
        }

        return DB::table('spots as f')
            ->join('spots as p', 'p.id', '=', 'f.parent_spot_id')
            ->whereIn('f.id', $spotIds)
            ->whereIn('f.category', self::FACILITIES)
            ->where('p.is_active', true)
            ->get(['f.id', 'f.name', 'f.parent_spot_id', 'p.name as park'])
            // Unnamed, or named after the park itself ("Spielplatz Blücherpark IV").
            ->filter(fn (object $row): bool => PlaceDisplayName::isGeneric($row->name)
                || (trim((string) $row->park) !== '' && str_contains(mb_strtolower((string) $row->name), mb_strtolower(trim((string) $row->park)))))
            ->mapWithKeys(fn (object $row): array => [(int) $row->id => (int) $row->parent_spot_id])
            ->all();
    }

    /**
     * What each park has: [park id => [kind => count]], most first.
     *
     * @param  list<int>  $parkIds
     * @return array<int, array<string, int>>
     */
    public function counts(array $parkIds): array
    {
        if ($parkIds === []) {
            return [];
        }

        return Spot::query()
            ->whereIn('parent_spot_id', $parkIds)
            ->whereIn('category', self::FACILITIES)
            ->where('is_active', true)
            ->get(['id', 'parent_spot_id', 'category', 'tags'])
            ->groupBy('parent_spot_id')
            ->mapWithKeys(fn ($facilities, int|string $parkId): array => [(int) $parkId => $facilities
                ->map(fn (Spot $spot): string => PlaceDisplayName::kind($spot->category->value, $spot->tags['sport'] ?? null))
                ->countBy()->sortDesc()->all()])
            ->all();
    }

    /**
     * "Football pitch · 2 tennis courts · Playground": what matched first, then the most.
     *
     * @param  array<string, int>  $counts
     * @param  list<string>  $first
     */
    public static function summary(array $counts, array $first = []): ?string
    {
        if ($counts === []) {
            return null;
        }
        $ordered = array_replace(array_intersect_key(array_flip($first), $counts), $counts);

        return collect(array_intersect_key($counts, $ordered))->sortKeysUsing(fn (string $a, string $b): int => array_search($a, array_keys($ordered), true) <=> array_search($b, array_keys($ordered), true))
            ->map(fn (int $count, string $kind): string => $count > 1 ? $count.' '.self::plural($kind) : $kind)
            ->take(4)
            ->implode(' · ');
    }

    /**
     * One entry per place: facilities in a park fold into the park, keeping
     * the first (nearest) position and the kinds that matched the ask. A park
     * too big to walk round (facilities more than SPLIT_KM apart, like the
     * Äußerer Grüngürtel) folds per Veedel instead: "Äußerer Grüngürtel (Klettenberg)".
     *
     * @param  list<int>  $spotIds  in rank order
     * @return list<array{id: int, part: ?string, members: list<int>}>
     */
    public function fold(array $spotIds): array
    {
        $parents = $this->parents($spotIds);
        $large = $this->large(array_values(array_unique($parents)));
        $veedels = $large === [] ? [] : Spot::query()->whereIn('id', array_keys($parents))->pluck('veedel', 'id')->all();
        $places = [];
        foreach ($spotIds as $id) {
            $park = $parents[$id] ?? null;
            $part = $park !== null && isset($large[$park]) ? ($veedels[$id] ?? null) : null;
            $key = $park === null ? (string) $id : $park.'|'.$part;
            $places[$key] ??= ['id' => $park ?? $id, 'part' => $part, 'members' => []];
            if ($park !== null) {
                $places[$key]['members'][] = $id;
            }
        }

        return array_values($places);
    }

    /** Facilities further apart than this make a park several places. */
    public const SPLIT_KM = 1.2;

    /**
     * Parks whose facilities spread wider than SPLIT_KM: [park id => true].
     *
     * @param  list<int>  $parkIds
     * @return array<int, true>
     */
    public function large(array $parkIds): array
    {
        if ($parkIds === []) {
            return [];
        }

        return DB::table('spots')->whereIn('parent_spot_id', $parkIds)->whereIn('category', self::FACILITIES)->where('is_active', true)
            ->groupBy('parent_spot_id')
            ->selectRaw('parent_spot_id, max(lat) - min(lat) AS dlat, max(lng) - min(lng) AS dlng, avg(lat) AS lat')
            ->get()
            ->filter(fn (object $row): bool => hypot((float) $row->dlat * 111.32, (float) $row->dlng * 111.32 * cos(deg2rad((float) $row->lat))) > self::SPLIT_KM)
            ->mapWithKeys(fn (object $row): array => [(int) $row->parent_spot_id => true])
            ->all();
    }

    /**
     * What one Veedel's part of a large park has: [kind => count].
     *
     * @return array<string, int>
     */
    public function partCounts(int $parkId, string $veedel): array
    {
        return Spot::query()->where('parent_spot_id', $parkId)->where('veedel', $veedel)
            ->whereIn('category', self::FACILITIES)->where('is_active', true)
            ->get(['category', 'tags'])
            ->map(fn (Spot $spot): string => PlaceDisplayName::kind($spot->category->value, $spot->tags['sport'] ?? null))
            ->countBy()->sortDesc()->all();
    }

    private static function plural(string $kind): string
    {
        $lower = mb_strtolower($kind);

        return match (true) {
            str_ends_with($lower, 'pitch') => $lower.'es',
            str_ends_with($lower, 's') => $lower,
            default => $lower.'s',
        };
    }
}
