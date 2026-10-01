<?php

use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Services\NearbyPlaces;
use Illuminate\Support\Facades\DB;

require __DIR__.'/bootstrap-local.php';
$labRoot = getenv('PLACES_LOCAL_PRIVATE');
$input = json_decode(file_get_contents($labRoot.'/held-facilities-input-public.json'), true, flags: JSON_THROW_ON_ERROR);
$proof = json_decode(file_get_contents($labRoot.'/facility-source-private.json'), true, flags: JSON_THROW_ON_ERROR);
$bySource = array_column($proof['records'], null, 'source_id');
$nodes = $proof['geometry_nodes'];
$outcomes = [];
$counts = [];
$qualifiedCategories = [];
DB::beginTransaction();
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
try {
    foreach ($input as $record) {
        $fresh = $bySource[$record['source_id']];
        $reason = array_values(array_diff($fresh['reasons'], ['point_changed_over_one_metre']));
        $matches = Spot::where('source', 'osm')->where('source_id', $record['source_id'])->get();
        if ($matches->count() !== 1) {
            $outcomes[] = ['source_id' => $record['source_id'], 'reasons' => ['nonunique_source_identity']];

            continue;
        }
        $spot = $matches->first();
        $facts = app(PlaceFacts::class)->resolve($spot);
        $geometryCheck = null;
        if ($fresh['current']['type'] === 'way' && $fresh['current']['visible']) {
            $xy = array_map(fn ($id) => [$nodes[$id]['lon'], $nodes[$id]['lat']], $fresh['current']['nodes']);
            $polygon = count($xy) >= 4 && $xy[0] === $xy[array_key_last($xy)];
            $geometry = json_encode(['type' => $polygon ? 'Polygon' : 'LineString', 'coordinates' => $polygon ? [$xy] : $xy], JSON_THROW_ON_ERROR);
            $g = DB::selectOne('WITH g AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),4326) AS shape) SELECT ST_IsValid(shape) AS valid, ST_Covers(shape,ST_SetSRID(ST_MakePoint(?,?),4326)) AS covers, ST_Y(ST_PointOnSurface(shape)) AS lat, ST_X(ST_PointOnSurface(shape)) AS lng FROM g', [$geometry, $record['lng'], $record['lat']]);
            $distance = NearbyPlaces::km($record['lat'], $record['lng'], (float) $g->lat, (float) $g->lng) * 1000;
            $geometryCheck = ['valid' => $g->valid, 'stored_point_covered' => $g->covers, 'representative_point_difference_m' => round($distance, 4)];
            if (! $g->valid || $distance > 1) {
                $reason[] = 'representative_geometry_changed';
            }
        } elseif (($fresh['distance_metres'] ?? PHP_FLOAT_MAX) > 1) {
            $reason[] = 'source_node_changed';
        }
        if (! $spot->is_active || $spot->is_recommendable || $spot->canonical_spot_id !== null) {
            $reason[] = 'unexpected_target_state';
        }
        if ($spot->category->value !== $record['category'] || $spot->tags !== $record['tags'] || abs($spot->lat - $record['lat']) > 0.00000011 || abs($spot->lng - $record['lng']) > 0.00000011) {
            $reason[] = 'target_source_projection_changed';
        }
        if ($facts['access']['value'] !== 'public' || $facts['access']['status'] !== 'known' || $facts['access']['conditional'] !== null) {
            $reason[] = 'public_access_not_established';
        }
        if ($facts['fee']['value'] !== 'unknown' || $spot->price_range !== null || $facts['name_kind'] !== 'descriptive' || $facts['location']['entrance_point']['status'] === 'verified') {
            $reason[] = 'reviewed_values_require_separate_handling';
        }
        if ($spot->factCorrections()->whereNull('revoked_at')->exists() || $spot->destination_spot_id !== null) {
            $reason[] = 'existing_review_or_group_requires_separate_handling';
        }
        $near = DB::table('spots')->where('id', '!=', $spot->id)->whereNotNull('source')->whereNull('canonical_spot_id')
            ->where('is_active', true)->where('category', $spot->category->value)
            ->whereBetween('lat', [$spot->lat - .00003, $spot->lat + .00003])->whereBetween('lng', [$spot->lng - .00005, $spot->lng + .00005])
            ->whereRaw('ST_DWithin(ST_SetSRID(ST_MakePoint(lng,lat),4326)::geography,ST_SetSRID(ST_MakePoint(?,?),4326)::geography,2)', [$spot->lng, $spot->lat])->pluck('id')->all();
        if ($near !== []) {
            $reason[] = 'nearby_same_category_source_identity';
        }
        $reason = array_values(array_unique($reason));
        $outcome = ['source_id' => $record['source_id'], 'spot_id' => $spot->id, 'category' => $spot->category->value,
            'reasons' => $reason, 'geometry_check' => $geometryCheck, 'supported_sports' => $facts['activities'],
            'source_proof_sha256' => $fresh['sha256'], 'nearby_source_ids' => $near,
            'spot' => $spot->getAttributes(), 'facts' => $facts, 'source' => $record, 'fresh' => $fresh];
        $outcomes[] = $outcome;
        foreach ($reason === [] ? ['ready_for_qualification_review'] : $reason as $code) {
            $counts[$code] = ($counts[$code] ?? 0) + 1;
        }
        if ($reason === []) {
            $qualifiedCategories[$record['category']] = ($qualifiedCategories[$record['category']] ?? 0) + 1;
        }
    }
    $legacy = [
        'source_groups' => DB::table('spots')->whereNull('source')->selectRaw('source_group, count(*) AS records')->groupBy('source_group')->get()->all(),
        'tagged_rows' => DB::table('spots')->whereNull('source')->whereRaw("tags IS NOT NULL AND tags::text NOT IN ('{}','[]','null')")->count(),
        'existing_observations' => DB::table('place_fact_observations as o')->join('spots as s', 's.id', '=', 'o.spot_id')->whereNull('s.source')->selectRaw('o.provider, count(*) AS records')->groupBy('o.provider')->get()->all(),
        'tag_keys' => DB::select("SELECT key, count(*) AS records FROM spots s CROSS JOIN LATERAL jsonb_object_keys(CASE WHEN jsonb_typeof(tags::jsonb)='object' THEN tags::jsonb ELSE '{}'::jsonb END) AS key WHERE s.source IS NULL GROUP BY key ORDER BY records DESC LIMIT 35"),
    ];
    $summary = ['status' => 'completed', 'checked_at' => gmdate('c'), 'records' => count($outcomes), 'reason_counts' => $counts,
        'qualification_review_categories' => $qualifiedCategories, 'legacy_provenance_hints' => $legacy,
        'stored_records' => Spot::count(), 'database_changed' => false, 'raw_rows_exported' => false];
} finally {
    DB::rollBack();
}
file_put_contents($labRoot.'/facility-target-private.json', json_encode(['summary' => $summary, 'records' => $outcomes], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents($labRoot.'/facility-target-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode($summary).PHP_EOL;
