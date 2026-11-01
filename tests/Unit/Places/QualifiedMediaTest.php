<?php

use App\Media\PublishedMediaSelector;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
use Tests\TestCase;

uses(TestCase::class);

function qualifiedPhotoPlace(array $match = [], array $asset = []): Spot
{
    $photo = (new MediaAsset)->forceFill(array_merge([
        'id' => 11, 'provider' => 'test-provider', 'rights_status' => 'approved',
        'health_status' => 'active', 'attribution' => 'Example, CC BY 4.0',
        'source_page_url' => 'https://example.test/photo',
    ], $asset));
    $attachment = (new MediaAttachment)->forceFill(array_merge([
        'id' => 7, 'mediable_id' => 2, 'role' => 'hero',
        'match_status' => 'accepted', 'match_method' => 'manual_review',
        'match_evidence' => ['subject' => 'The exact place'], 'match_reviewed_at' => now(),
    ], $match))->setRelation('mediaAsset', $photo);

    return (new Spot)->forceFill(['id' => 2])
        ->setRelation('mediaAttachments', collect([$attachment]))
        ->setRelation('identityAliases', collect());
}

test('place photos require concrete accepted match evidence even when manually locked', function (array $match) {
    $spot = qualifiedPhotoPlace(array_merge(['is_manually_locked' => true], $match));
    expect(app(PublishedMediaSelector::class)->select($spot, 'hero'))->toBeNull();
})->with([
    'pending' => [['match_status' => 'pending']],
    'rejected' => [['match_status' => 'rejected']],
    'empty evidence' => [['match_evidence' => []]],
    'missing review date' => [['match_reviewed_at' => null]],
]);

test('place photos require attribution and an original source page', function (array $asset) {
    expect(app(PublishedMediaSelector::class)->select(qualifiedPhotoPlace(asset: $asset), 'hero'))->toBeNull();
})->with([
    'missing attribution' => [['attribution' => null]],
    'blank source' => [['source_page_url' => ' ']],
]);

test('municipal place photo origin remains excluded despite accepted match evidence', function () {
    expect(app(PublishedMediaSelector::class)->select(qualifiedPhotoPlace(asset: ['provider' => 'stadt-koeln']), 'hero'))->toBeNull();
});

test('qualified photo is selected without changing its original rights or check dates', function () {
    $spot = qualifiedPhotoPlace();
    expect(app(PublishedMediaSelector::class)->select($spot, 'hero')?->id)->toBe(11);
});
