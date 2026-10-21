<?php

require_once dirname(__DIR__, 3).'/docs/reviews/2026-10-05-catalogue/HistoricalCatalogueSnapshot.php';

test('historical import remaps the complete place relationship graph without rewriting source evidence', function () {
    $tables = [
        'spots' => [['id' => '3', 'canonical_spot_id' => '8', 'parent_spot_id' => null], ['id' => '8', 'canonical_spot_id' => null, 'parent_spot_id' => null]],
        'place_fact_observations' => [['id' => '19', 'spot_id' => '8', 'payload' => '{"source_id":"node/8"}']],
        'place_reconciliations' => [['id' => '2', 'alias_spot_id' => '3', 'canonical_spot_id' => '8', 'snapshot' => '{"alias":{"id":3}}']],
        'media_attachments' => [['id' => '4', 'mediable_type' => 'App\\Models\\Spot', 'mediable_id' => '8', 'media_asset_id' => '3']],
        'park_areas' => [['id' => '5', 'parent_spot_id' => '8']],
    ];
    $mapped = HistoricalCatalogueSnapshot::remap($tables, 1000000);
    expect($mapped['spots'][0]['id'])->toBe('1000003')
        ->and($mapped['spots'][0]['canonical_spot_id'])->toBe('1000008')
        ->and($mapped['place_fact_observations'][0]['spot_id'])->toBe('1000008')
        ->and($mapped['place_fact_observations'][0]['payload'])->toBe('{"source_id":"node/8"}')
        ->and($mapped['place_reconciliations'][0]['alias_spot_id'])->toBe('1000003')
        ->and($mapped['place_reconciliations'][0]['snapshot'])->toBe('{"alias":{"id":3}}')
        ->and($mapped['media_attachments'][0]['mediable_id'])->toBe('1000008')
        ->and($mapped['media_attachments'][0]['media_asset_id'])->toBe('3')
        ->and($mapped['park_areas'][0]['parent_spot_id'])->toBe('1000008');
});

test('historical import refuses foreign relationships and non-place attachments', function (array $tables) {
    expect(fn () => HistoricalCatalogueSnapshot::remap($tables, 1000000))->toThrow(DomainException::class);
})->with([
    'missing canonical' => [['spots' => [['id' => '1', 'canonical_spot_id' => '9']]]],
    'event attachment' => [['spots' => [['id' => '1']], 'media_attachments' => [['mediable_type' => 'App\\Models\\Event', 'mediable_id' => '1']]]],
    'duplicate identity' => [['spots' => [['id' => '1'], ['id' => '1']]]],
]);

test('historical import refuses unknown or private tables', function () {
    expect(fn () => HistoricalCatalogueSnapshot::remap(['spots' => [], 'users' => [['id' => '1']]], 1000000))->toThrow(DomainException::class);
});
