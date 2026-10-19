<?php

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $mask = umask();
    require_once dirname(__DIR__, 3).'/docs/places/local-catalogue/common.php';
    umask($mask);
    require_once dirname(__DIR__, 3).'/docs/places/production-pack/apply-pack.php';
    if (is_file(dirname(__DIR__, 3).'/docs/places/ten-thousand-catalogue/native-checks.php')) {
        require_once dirname(__DIR__, 3).'/docs/places/ten-thousand-catalogue/native-checks.php';
    }
});

function additionalSourceFixture(): array
{
    $tags = ['name' => 'Neighbourhood Shop', 'shop' => 'supermarket'];
    $record = ['key' => 'osm:way/20', 'source' => 'osm', 'source_id' => 'way/20', 'source_url' => 'https://www.openstreetmap.org/way/20', 'licenses' => ['ODbL-1.0'], 'existing_id' => null, 'holds' => [], 'name' => $tags['name'], 'source_name' => $tags['name'], 'category' => 'supermarket', 'tags' => $tags, 'source_type' => 'shop:supermarket', 'lat' => 50.94, 'lng' => 6.95, 'raw' => ['nodes' => [1, 2, 3, 4, 1]], 'observation' => ['fee' => []], 'is_recommendable' => true];
    $current = ['type' => 'way', 'id' => 20, 'version' => '3', 'visible' => true, 'tags' => $tags, 'nodes' => [1, 2, 3, 4, 1]];
    $nodes = [1 => ['visible' => true, 'lat' => 50.9399, 'lon' => 6.9499], 2 => ['visible' => true, 'lat' => 50.9399, 'lon' => 6.9501], 3 => ['visible' => true, 'lat' => 50.9401, 'lon' => 6.9501], 4 => ['visible' => true, 'lat' => 50.9401, 'lon' => 6.9499]];
    $proof = ['summary' => ['checked_at' => now()->toIso8601String()], 'geometry_nodes' => $nodes, 'records' => [['source_id' => 'way/20', 'reasons' => [], 'current' => $current, 'after' => $current]]];

    return [$record, $proof, ['shop' => ['supermarket' => 'supermarket']]];
}

test('additional venue proof validates full geometry and exact source type', function (string $mutation, ?string $reason) {
    $this->freezeTime();
    [$record, $proof, $types] = additionalSourceFixture();
    if ($mutation === 'topology') {
        $record['raw']['nodes'] = [1, 4, 3, 2, 1];
    } elseif ($mutation === 'point') {
        $record['lat'] += .001;
    } elseif ($mutation === 'tag') {
        $proof['records'][0]['current']['tags']['name'] = 'Changed Name';
    } elseif ($mutation === 'type') {
        $record['category'] = 'pharmacy';
    } elseif ($mutation === 'missing_node') {
        unset($proof['geometry_nodes'][2]);
    } elseif ($mutation === 'private') {
        $record['tags']['access'] = 'private';
        $proof['records'][0]['current']['tags']['access'] = 'private';
        $proof['records'][0]['after'] = $proof['records'][0]['current'];
    }
    [$accepted, $held] = namedPreparedRecords(['records' => [$record]], $proof, [], $types);
    expect(count($accepted))->toBe($reason === null ? 1 : 0);
    if ($reason !== null) {
        expect($held[0]['reasons'])->toContain($reason);
    } else {
        expect($accepted[0]['observed_at'])->toBe($proof['summary']['checked_at'])
            ->and($accepted[0]['observation']['fee']['raw'] ?? null)->toBeNull();
    }
})->with([
    ['none', null], ['topology', 'frozen_way_topology_missing_or_changed'],
    ['point', 'representative_geometry_changed'], ['tag', 'source_identity_tags_or_visibility_changed'],
    ['type', 'source_type_category_differs'], ['missing_node', 'incomplete_geometry'],
    ['private', 'restricted_or_conditional_access'],
]);

test('additional venue proof rejects an existing source owner and stale evidence', function () {
    $this->freezeTime();
    [$record, $proof, $types] = additionalSourceFixture();
    Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/20', 'category' => 'supermarket']);
    [$accepted, $held] = namedPreparedRecords(['records' => [$record]], $proof, [], $types);
    expect($accepted)->toBe([])->and($held[0]['reasons'])->toContain('source_identity_already_owned');
    $proof['summary']['checked_at'] = now()->subDays(2)->toIso8601String();
    expect(fn () => namedPreparedRecords(['records' => [$record]], $proof, [], $types))->toThrow(RuntimeException::class, 'Fresh absolute source proof required');
});

test('prepared venue replay preserves native rows and observations after elapsed time', function () {
    $this->freezeTime();
    DB::table('veedels')->insert([
        'name' => 'Replay Quarter', 'bezirk' => 'Test district',
        'boundary' => DB::raw("ST_Multi(ST_GeomFromText('POLYGON((6.94 50.93,6.96 50.93,6.96 50.95,6.94 50.95,6.94 50.93))',4326))"),
    ]);
    [$record, $proof, $types] = additionalSourceFixture();
    $record['tags'] += ['addr:street' => 'Test Street', 'website' => 'https://example.com', 'opening_hours' => 'Mo-Fr 09:00-18:00'];
    $record += ['address' => 'Test Street', 'website' => 'https://example.com', 'phone' => null];
    $record['observation'] = ['name' => $record['name'], 'location' => ['lat' => $record['lat'], 'lng' => $record['lng'], 'kind' => 'source_center'], 'fee' => ['raw' => null], 'access' => ['raw' => null], 'hours' => ['raw' => $record['tags']['opening_hours']], 'contact' => ['address' => $record['address'], 'website' => $record['website'], 'phone' => null]];
    $proof['records'][0]['current']['tags'] = $record['tags'];
    $proof['records'][0]['after'] = $proof['records'][0]['current'];
    [$accepted, $held] = namedPreparedRecords(['records' => [$record]], $proof, [], $types);
    expect($held)->toBe([]);
    $hash = hash('sha256', 'replay-test');
    $mapping = applyPreparedPlaces($accepted, [], $hash);
    $id = $mapping[$record['key']]['id'];
    $row = static fn (): array => json_decode(DB::selectOne('SELECT row_to_json(s)::text AS raw FROM spots s WHERE id=?', [$id])->raw, true, flags: JSON_THROW_ON_ERROR);
    $before = $row();
    $observations = PlaceFactObservation::query()->get()->toArray();
    $this->travel(2)->seconds();
    $accepted[0]['existing_id'] = $id;
    applyPreparedPlaces($accepted, [$id => $before], $hash);
    expect(preparedPlaceRowFingerprint($row()))->toBe(preparedPlaceRowFingerprint($before))
        ->and(PlaceFactObservation::query()->get()->toArray())->toBe($observations);
});
