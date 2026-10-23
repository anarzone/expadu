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
     * the first (nearest) position and the kinds that matched the ask.
     *
     * @param  list<int>  $spotIds  in rank order
     * @return list<array{id: int, members: list<int>}>
     */
    public function fold(array $spotIds): array
    {
        $parents = $this->parents($spotIds);
        $places = [];
        foreach ($spotIds as $id) {
            $key = $parents[$id] ?? $id;
            $places[$key] ??= ['id' => $key, 'members' => []];
            if (isset($parents[$id])) {
                $places[$key]['members'][] = $id;
            }
        }

        return array_values($places);
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
