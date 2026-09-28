<?php

namespace App\Composer;

use App\Models\Spot;
use App\Places\PlaceCapabilities;
use App\Services\NearbyPlaces;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cheap search hints, never publishable facts. Include every source point and
 * every possible positive claim, even superseded ones, so narrowing cannot
 * discard a match that the shared fact resolver would accept.
 */
class PlaceSearchHints
{
    /** @param Builder<Spot> $query
     * @return list<array{id: int, category: string, minimum_km: float}>
     */
    public function ordered(Builder $query, Constraints $constraints, float $lat, float $lng): array
    {
        $hints = [];
        foreach ($query->toBase()->get(['spots.id', 'spots.category', 'spots.lat', 'spots.lng', 'spots.tags']) as $row) {
            $tags = json_decode($row->tags ?? '{}', true) ?: [];
            $hints[$row->id] = [
                'id' => (int) $row->id,
                'category' => $row->category,
                'minimum_km' => $this->distance($row->lat, $row->lng, $lat, $lng),
                'activities' => PlaceCapabilities::activities(is_string($tags['sport'] ?? null) ? $tags['sport'] : null),
                'possibly_free' => $this->free($tags['fee'] ?? null),
            ];
        }
        if ($hints === []) {
            return [];
        }
        foreach (array_chunk(array_keys($hints), 1000) as $ids) {
            $observations = DB::table('place_fact_observations')->whereIn('spot_id', $ids)
                ->select('spot_id')->selectRaw("payload->'location' AS location, payload #>> '{practical,sport}' AS sport, payload #>> '{fee,raw}' AS fee")->get();
            foreach ($observations as $row) {
                $point = json_decode($row->location ?? '{}', true) ?: [];
                $hint = &$hints[$row->spot_id];
                $hint['minimum_km'] = min($hint['minimum_km'], $this->distance($point['lat'] ?? null, $point['lng'] ?? null, $lat, $lng));
                $hint['activities'] = array_unique([...$hint['activities'], ...PlaceCapabilities::activities($row->sport)]);
                $hint['possibly_free'] = $hint['possibly_free'] || $this->free($row->fee);
                unset($hint);
            }
            $corrections = DB::table('place_fact_corrections')->whereIn('spot_id', $ids)->whereNull('revoked_at')
                ->whereIn('field', ['fee', 'entrance_point'])->get(['spot_id', 'field', 'value']);
            foreach ($corrections as $row) {
                $value = json_decode($row->value, true);
                $hint = &$hints[$row->spot_id];
                if ($row->field === 'entrance_point') {
                    $hint['minimum_km'] = min($hint['minimum_km'], $this->distance($value['lat'] ?? null, $value['lng'] ?? null, $lat, $lng));
                } else {
                    $hint['possibly_free'] = $hint['possibly_free'] || $this->free($value['value'] ?? null);
                }
                unset($hint);
            }
        }
        $hints = array_values(array_filter($hints, fn (array $hint): bool => ($constraints->radiusKm === null || $hint['minimum_km'] <= $constraints->radiusKm)
            && ($constraints->budget !== 'free' || $hint['possibly_free'])
            && ($constraints->activities === [] || array_intersect($constraints->activities, $hint['activities']) !== [])
        ));
        usort($hints, fn (array $a, array $b): int => [$a['minimum_km'], $a['id']] <=> [$b['minimum_km'], $b['id']]);

        return $hints;
    }

    private function free(mixed $value): bool
    {
        return is_string($value) && in_array(mb_strtolower(trim($value)), ['no', 'free'], true);
    }

    private function distance(mixed $pointLat, mixed $pointLng, float $lat, float $lng): float
    {
        return is_numeric($pointLat) && is_numeric($pointLng)
            ? NearbyPlaces::km($lat, $lng, (float) $pointLat, (float) $pointLng)
            : INF;
    }
}
