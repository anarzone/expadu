<?php

use App\Models\Spot;
use App\Places\PlaceCapabilities;
use App\Places\PlaceFacts;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function heldPreparedRecords(array $selection, array $proof, array $before): array
{
    $checkedAt = $proof['summary']['checked_at'];
    localCheck((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $checkedAt)
        && CarbonImmutable::parse($checkedAt)->betweenIncluded(now()->subDay(), now()), 'Fresh absolute source proof required.');
    $freshById = array_column($proof['records'], null, 'source_id');
    $prepared = $held = [];
    foreach ($selection['records'] as $record) {
        $reasons = [];
        $fresh = $freshById[$record['source_id']] ?? null;
        $spot = Spot::find($record['existing_id']);
        if ($fresh === null || $fresh['reasons'] !== []) {
            $reasons[] = 'fresh_source_requires_review';
        }
        if ($spot === null || $spot->source !== 'osm' || $spot->source_id !== $record['source_id']
            || Spot::where('source', 'osm')->where('source_id', $record['source_id'])->count() !== 1) {
            $reasons[] = 'exact_existing_source_identity_differs';
        } elseif (! $spot->is_active || $spot->is_recommendable || $spot->canonical_spot_id !== null
            || $spot->destination_spot_id !== null || ! $spot->category->isActivityFacility()
            || $spot->category->value !== $record['category'] || $spot->price_range !== null) {
            $reasons[] = 'target_not_independent_held_facility';
        }
        foreach ($record['legacy_counterpart_ids'] as $id) {
            $legacy = $before[$id] ?? null;
            if ($legacy === null || $legacy['source'] !== null || $legacy['source_id'] !== null
                || $legacy['is_active'] || $legacy['is_recommendable'] || $legacy['canonical_spot_id'] !== null) {
                $reasons[] = 'legacy_counterpart_changed';
            }
        }
        if ($record['legacy_counterpart_ids'] === [] || $record['original_holds'] !== ['identity_ambiguity']) {
            $reasons[] = 'missing_original_ambiguity_evidence';
        }
        if ($spot !== null) {
            if ($spot->factCorrections()->whereNull('revoked_at')->exists()
                || $spot->factObservations()->where('record_kind', '!=', 'source')->exists()
                || $spot->factObservations()->where('observed_at', '>', $checkedAt)->exists()
                || ($spot->last_seen_at !== null && $spot->last_seen_at->gt(CarbonImmutable::parse($checkedAt)))) {
                $reasons[] = 'newer_or_reviewed_history';
            }
            if (NearbyPlaces::km($spot->lat, $spot->lng, $record['lat'], $record['lng']) * 1000 > 25) {
                $reasons[] = 'stored_point_differs';
            }
            $overlap = Spot::where('id', '!=', $spot->id)->whereNotNull('source')->whereNull('canonical_spot_id')
                ->where('is_active', true)->where('category', $record['category'])
                ->whereBetween('lat', [$record['lat'] - .00003, $record['lat'] + .00003])
                ->whereBetween('lng', [$record['lng'] - .00005, $record['lng'] + .00005])
                ->whereRaw('ST_DWithin(ST_SetSRID(ST_MakePoint(lng,lat),4326)::geography,ST_SetSRID(ST_MakePoint(?,?),4326)::geography,2)', [$record['lng'], $record['lat']])->exists();
            if ($overlap) {
                $reasons[] = 'overlapping_source_identity';
            }
        }
        $current = $fresh['current'] ?? null;
        if ($current === null || $current['visible'] !== true || (int) $current['version'] < 1
            || $record['source_id'] !== $current['type'].'/'.$current['id']
            || preparedPlaceRowFingerprint($current['tags']) !== preparedPlaceRowFingerprint($record['tags'])) {
            $reasons[] = 'source_identity_tags_or_visibility_changed';
        } elseif ($current['type'] === 'way') {
            $frozenNodes = $record['raw']['nodes'] ?? null;
            if (! is_array($frozenNodes) || ! array_is_list($frozenNodes) || count($frozenNodes) < 2
                || ($current['nodes'] ?? null) !== $frozenNodes) {
                $reasons[] = 'frozen_way_topology_missing_or_changed';
            }
            $nodes = $proof['geometry_nodes'];
            $xy = [];
            foreach ($current['nodes'] as $id) {
                if (! isset($nodes[$id]['lat'], $nodes[$id]['lon']) || ! $nodes[$id]['visible']) {
                    $reasons[] = 'incomplete_geometry';
                    break;
                }
                $xy[] = [$nodes[$id]['lon'], $nodes[$id]['lat']];
            }
            if (count($xy) < 2 || $current !== $fresh['after']) {
                $reasons[] = 'changed_or_incomplete_way';
            } else {
                $polygon = count($xy) >= 4 && $xy[0] === $xy[array_key_last($xy)];
                $geometry = json_encode(['type' => $polygon ? 'Polygon' : 'LineString', 'coordinates' => $polygon ? [$xy] : $xy], JSON_THROW_ON_ERROR);
                $g = DB::selectOne('WITH g AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),4326) AS shape) SELECT ST_IsValid(shape) AS valid, ST_Y(ST_PointOnSurface(shape)) AS lat, ST_X(ST_PointOnSurface(shape)) AS lng FROM g', [$geometry]);
                if (! $g->valid || NearbyPlaces::km($record['lat'], $record['lng'], (float) $g->lat, (float) $g->lng) * 1000 > 1) {
                    $reasons[] = 'representative_geometry_changed';
                }
            }
        } elseif (! isset($current['lat'], $current['lon'])
            || NearbyPlaces::km($record['lat'], $record['lng'], $current['lat'], $current['lon']) * 1000 > 1) {
            $reasons[] = 'source_node_moved';
        }
        $tags = $record['tags'];
        $value = static fn (string $key): string => mb_strtolower(trim((string) ($tags[$key] ?? '')));
        if (! in_array($value('access'), ['yes', 'public', 'permissive'], true) || $value('access:conditional') !== '') {
            $reasons[] = 'public_access_not_established';
        }
        if (($record['expected_fee'] === 'free' && $value('fee') !== 'no')
            || ($record['expected_fee'] === 'unknown' && ($value('fee') !== '' || $value('charge') !== ''))
            || ! in_array($record['expected_fee'], ['free', 'unknown'], true)
            || ! in_array($value('charge'), ['', '0', '0 eur', '0 €'], true)
            || $value('fee:conditional') !== '' || $value('charge:conditional') !== '') {
            $reasons[] = 'fee_evidence_differs';
        }
        if ($reasons !== []) {
            $held[] = ['spot_id' => $record['existing_id'], 'source_id' => $record['source_id'], 'reasons' => array_values(array_unique($reasons))];

            continue;
        }
        $record['observed_at'] = $checkedAt;
        $record['is_recommendable'] = false;
        $record['observation']['practical'] = PlaceCapabilities::sourceTags($tags);
        $record['observation']['fee'] += ['conditional' => null, 'charge' => $tags['charge'] ?? null, 'charge_conditional' => null];
        $record['expected_existing_sha256'] = preparedPlaceRowFingerprint($before[$spot->id]);
        $prepared[] = $record;
    }

    return [$prepared, $held];
}

function heldAssertFacts(array $record): array
{
    $spot = Spot::findOrFail($record['existing_id']);
    $facts = app(PlaceFacts::class)->resolve($spot);
    localCheck(! $spot->is_recommendable && $spot->is_active && $spot->source_id === $record['source_id']
        && $facts['name_kind'] === 'descriptive' && $facts['access']['value'] === 'public'
        && $facts['access']['status'] === 'known' && $facts['access']['conditional'] === null
        && $facts['fee']['value'] === $record['expected_fee'] && $spot->price_range === null
        && $facts['conflicts'] === [] && $facts['location']['map_point']['status'] === 'known'
        && abs($facts['location']['map_point']['lat'] - $record['lat']) < .00000011
        && abs($facts['location']['map_point']['lng'] - $record['lng']) < .00000011,
        'Refreshed source and effective facts disagree for '.$spot->id);
    $activities = PlaceCapabilities::activities($record['tags']['sport'] ?? null);
    localCheck($facts['activities'] === $activities, 'Sport capabilities lost during refresh.');

    return $facts;
}
