<?php

use App\Models\Spot;

beforeEach(function () {
    $mask = umask();
    require_once dirname(__DIR__, 3).'/docs/places/local-catalogue/common.php';
    umask($mask);
    require_once dirname(__DIR__, 3).'/docs/places/production-pack/apply-pack.php';
    require_once dirname(__DIR__, 3).'/docs/places/held-facilities/native-checks.php';
});

test('native held facility checks require the frozen way node sequence even when its point is unchanged', function ($raw, $expected) {
    $this->freezeTime();
    $tags = ['access' => 'yes', 'leisure' => 'pitch', 'sport' => 'soccer'];
    $place = Spot::factory()->create(['category' => 'pitch', 'source' => 'osm', 'source_id' => 'way/20',
        'name' => 'Football pitch', 'tags' => $tags, 'lat' => 50.94, 'lng' => 6.95,
        'is_active' => true, 'is_recommendable' => false, 'price_range' => null, 'last_seen_at' => null]);
    $legacy = Spot::factory()->create(['category' => 'pitch', 'source' => null, 'source_id' => null,
        'is_active' => false, 'is_recommendable' => false]);
    $current = ['type' => 'way', 'id' => 20, 'version' => 3, 'visible' => true, 'tags' => $tags, 'nodes' => [1, 2, 3, 4, 1]];
    $nodes = [
        1 => ['visible' => true, 'lat' => 50.9399, 'lon' => 6.9499],
        2 => ['visible' => true, 'lat' => 50.9399, 'lon' => 6.9501],
        3 => ['visible' => true, 'lat' => 50.9401, 'lon' => 6.9501],
        4 => ['visible' => true, 'lat' => 50.9401, 'lon' => 6.9499],
    ];
    $record = ['source_id' => 'way/20', 'existing_id' => $place->id, 'category' => 'pitch', 'tags' => $tags,
        'lat' => 50.94, 'lng' => 6.95, 'expected_fee' => 'unknown', 'raw' => $raw,
        'legacy_counterpart_ids' => [$legacy->id], 'original_holds' => ['identity_ambiguity'], 'observation' => ['fee' => []]];
    $proof = ['summary' => ['checked_at' => now()->toIso8601String()], 'geometry_nodes' => $nodes,
        'records' => [['source_id' => 'way/20', 'reasons' => [], 'current' => $current, 'after' => $current]]];
    [$prepared, $held] = heldPreparedRecords(['records' => [$record]], $proof,
        [$place->id => $place->fresh()->toArray(), $legacy->id => $legacy->fresh()->toArray()]);
    expect(count($prepared))->toBe($expected ? 1 : 0);
    if (! $expected) {
        expect($held[0]['reasons'])->toContain('frozen_way_topology_missing_or_changed');
    }
})->with([
    'matching frozen topology' => [['nodes' => [1, 2, 3, 4, 1]], true],
    'changed before both fresh reads' => [['nodes' => [1, 4, 3, 2, 1]], false],
    'missing frozen node sequence' => [[], false],
    'empty frozen node sequence' => [['nodes' => []], false],
]);
