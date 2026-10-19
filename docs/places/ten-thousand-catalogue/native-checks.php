<?php

use App\Enums\SpotCategory;
use App\Models\Spot;
use App\Places\PlaceCapabilities;
use App\Services\NearbyPlaces;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function namedSourcePolicyReasons(array $tags): array
{
    $value = static fn (string $key): string => mb_strtolower(trim((string) ($tags[$key] ?? '')));
    $reasons = [];
    if (in_array($value('access'), ['no', 'private', 'customers', 'members', 'permit', 'destination', 'agricultural', 'forestry', 'delivery'], true) || $value('access:conditional') !== '') {
        $reasons[] = 'restricted_or_conditional_access';
    }
    if ($value('fee:conditional') !== '' || $value('charge:conditional') !== '') {
        $reasons[] = 'conditional_fee_not_supported_by_deployed_resolver';
    }
    if (($value('fee') === 'no' && ! in_array($value('charge'), ['', '0', '0 eur', '0 €'], true)) || ($value('fee') === 'yes' && in_array($value('charge'), ['0', '0 eur', '0 €'], true))) {
        $reasons[] = 'conflicting_fee_and_charge';
    }
    if (! in_array($value('reservation'), ['', 'no', 'yes', 'recommended'], true) || ! in_array($value('membership'), ['', 'no'], true)) {
        $reasons[] = 'booking_or_membership_requires_supported_constraints';
    }
    foreach (['disused', 'abandoned', 'demolished', 'construction'] as $key) {
        if ($value($key) === 'yes' || array_any(array_keys($tags), static fn (string $tag): bool => str_starts_with($tag, $key.':'))) {
            $reasons[] = 'lifecycle_requires_review';
        }
    }
    if ($value('end_date') !== '' || $value('closing_date') !== '') {
        $reasons[] = 'dated_lifecycle_requires_review';
    }
    if (in_array($value('opening_hours'), ['closed', 'off'], true)) {
        $reasons[] = 'closed_hours';
    }

    return array_values(array_unique($reasons));
}

function namedPreparedRecords(array $selection, array $proof, array $before, array $typeMap): array
{
    $checkedAt = $proof['summary']['checked_at'];
    localCheck((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $checkedAt)
        && CarbonImmutable::parse($checkedAt)->betweenIncluded(now()->subDay(), now()), 'Fresh absolute source proof required.');
    $freshById = array_column($proof['records'], null, 'source_id');
    localCheck(count($freshById) === count($proof['records']), 'Duplicate fresh source identity.');
    $keys = array_column($selection['records'], 'source_id');
    localCheck(count(array_unique($keys)) === count($keys), 'Duplicate selected source identity.');
    $owners = Spot::where('source', 'osm')->pluck('source_id')->all();
    $frozenOwners = array_column(array_filter($before, static fn (array $row): bool => ($row['source'] ?? null) === 'osm'), 'source_id');
    $owners = array_fill_keys([...$owners, ...$frozenOwners], true);
    $prepared = $held = [];
    foreach ($selection['records'] as $record) {
        $fresh = $freshById[$record['source_id']] ?? null;
        $current = $fresh['current'] ?? null;
        $tags = $record['tags'];
        $reasons = namedSourcePolicyReasons($tags);
        $category = SpotCategory::tryFrom($record['category']);
        if (($record['existing_id'] ?? null) !== null || isset($owners[$record['source_id']])) {
            $reasons[] = 'source_identity_already_owned';
        }
        if ($record['source'] !== 'osm' || $record['licenses'] !== ['ODbL-1.0'] || $record['holds'] !== [] || ! $record['is_recommendable']) {
            $reasons[] = 'unreviewed_source_or_licence';
        }
        [$typeKey, $typeValue] = explode(':', $record['source_type'], 2);
        if ($category === null || $category->isActivityFacility() || ($typeMap[$typeKey][$typeValue] ?? null) !== $record['category'] || ($tags[$typeKey] ?? null) !== $typeValue) {
            $reasons[] = 'source_type_category_differs';
        }
        if (! is_string($record['source_name']) || mb_strlen(trim($record['source_name'])) < 2 || mb_strlen($record['source_name']) > 255
            || $record['name'] !== $record['source_name'] || ($tags['name'] ?? null) !== $record['source_name']
            || str_contains($record['name'], '<') || str_contains(mb_strtolower($record['name']), 'http')) {
            $reasons[] = 'missing_or_invalid_source_name';
        }
        if ($fresh === null || $fresh['reasons'] !== []) {
            $reasons[] = 'fresh_source_requires_review';
        }
        if ($current === null || ($current['visible'] ?? false) !== true || (int) ($current['version'] ?? 0) < 1
            || $record['source_id'] !== ($current['type'] ?? '').'/'.($current['id'] ?? '')
            || $record['source_url'] !== 'https://www.openstreetmap.org/'.$record['source_id']
            || preparedPlaceRowFingerprint($current['tags'] ?? []) !== preparedPlaceRowFingerprint($tags)) {
            $reasons[] = 'source_identity_tags_or_visibility_changed';
        } elseif ($current['type'] === 'way') {
            $frozen = $record['raw']['nodes'] ?? null;
            if (! is_array($frozen) || ! array_is_list($frozen) || count($frozen) < 2 || $current['nodes'] !== $frozen) {
                $reasons[] = 'frozen_way_topology_missing_or_changed';
            }
            $xy = [];
            foreach ($current['nodes'] as $id) {
                $node = $proof['geometry_nodes'][$id] ?? null;
                if ($node === null || ($node['visible'] ?? false) !== true || ! isset($node['lat'], $node['lon']) || ! is_finite((float) $node['lat']) || ! is_finite((float) $node['lon'])) {
                    $reasons[] = 'incomplete_geometry';
                    break;
                }
                $xy[] = [$node['lon'], $node['lat']];
            }
            if (count($xy) < 2 || $current !== ($fresh['after'] ?? null)) {
                $reasons[] = 'changed_or_incomplete_way';
            } elseif (! in_array('incomplete_geometry', $reasons, true)) {
                $polygon = count($xy) >= 4 && $xy[0] === $xy[array_key_last($xy)];
                $geometry = json_encode(['type' => $polygon ? 'Polygon' : 'LineString', 'coordinates' => $polygon ? [$xy] : $xy], JSON_THROW_ON_ERROR);
                $point = DB::selectOne('WITH g AS (SELECT ST_SetSRID(ST_GeomFromGeoJSON(?),4326) AS shape) SELECT ST_IsValid(shape) AS valid, ST_Y(ST_PointOnSurface(shape)) AS lat, ST_X(ST_PointOnSurface(shape)) AS lng FROM g', [$geometry]);
                if (! $point->valid || $point->lat === null || $point->lng === null || NearbyPlaces::km($record['lat'], $record['lng'], (float) $point->lat, (float) $point->lng) * 1000 > 1) {
                    $reasons[] = 'representative_geometry_changed';
                }
            }
        } elseif ($current['type'] !== 'node' || ! isset($current['lat'], $current['lon']) || ! is_finite((float) $current['lat']) || ! is_finite((float) $current['lon'])
            || NearbyPlaces::km($record['lat'], $record['lng'], (float) $current['lat'], (float) $current['lon']) * 1000 > 1) {
            $reasons[] = 'source_node_moved';
        }
        if (! is_numeric($record['lat']) || ! is_numeric($record['lng']) || ! is_finite((float) $record['lat']) || ! is_finite((float) $record['lng'])
            || abs((float) $record['lat']) > 90 || abs((float) $record['lng']) > 180) {
            $reasons[] = 'invalid_source_point';
        }
        if ($reasons !== []) {
            $held[] = ['source_id' => $record['source_id'], 'reasons' => array_values(array_unique($reasons))];

            continue;
        }
        $record['observed_at'] = $checkedAt;
        $record['observation']['practical'] = PlaceCapabilities::sourceTags($tags);
        $prepared[] = $record;
    }

    return [$prepared, $held];
}
