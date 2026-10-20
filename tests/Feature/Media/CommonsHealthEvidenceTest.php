<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\CaptureMediaCandidate;
use App\Media\CommonsPhotoResolver;
use App\Media\MediaAssetValidator;
use App\Media\MediaCandidate;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('Commons metadata cannot certify delivered image health or its byte checksum', function () {
    Queue::fake();
    $bytes = UploadedFile::fake()->image('photo.jpg', 800, 500)->getContent();
    Http::fake(['upload.wikimedia.org/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg']), 'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [1 => [
        'title' => 'File:Exact park.jpg',
        'imageinfo' => [[
            'url' => 'https://upload.wikimedia.org/original.jpg',
            'thumburl' => 'https://upload.wikimedia.org/thumb.jpg',
            'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:Exact_park.jpg',
            'mime' => 'image/jpeg', 'width' => 2400, 'height' => 1600,
            'thumbwidth' => 1200, 'thumbheight' => 800, 'sha1' => str_repeat('a', 40),
            'extmetadata' => [
                'Artist' => ['value' => 'Independent Photographer'],
                'LicenseShortName' => ['value' => 'CC BY-SA 4.0'],
                'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
            ],
        ]],
    ]]]])]);
    $resolver = app(CommonsPhotoResolver::class);
    $metadata = $resolver->commonsMetadata(['Exact park.jpg'])['Exact park.jpg'];
    $spot = Spot::factory()->create();
    $attachment = app(CaptureMediaCandidate::class)->execute($spot, $resolver->candidate(
        'Exact_park.jpg', $metadata, 'accepted', 'osm_wikimedia_commons_tag', ['filename' => 'Exact_park.jpg'],
    ));
    expect($attachment->mediaAsset->rights_status)->toBe('approved')
        ->and($attachment->mediaAsset->health_status)->toBe('pending')
        ->and($attachment->mediaAsset->checksum)->toBeNull()
        ->and($attachment->mediaAsset->last_verified_at)->toBeNull()
        ->and($attachment->mediaAsset->metadata['commons_original_sha1'])->toBe(str_repeat('a', 40))
        ->and(app(PublishedMediaSelector::class)->select($spot))->toBeNull();
    Queue::assertPushed(ValidateMediaAssetJob::class);
    expect(app(MediaAssetValidator::class)->validate($attachment->mediaAsset))->toBe('active')
        ->and($attachment->mediaAsset->fresh()->checksum)->toBe(hash('sha256', $bytes))
        ->and(app(PublishedMediaSelector::class)->select($spot->fresh()))->not->toBeNull();
});

test('authoritative metadata updates rights without erasing same URL validated bytes', function () {
    Queue::fake();
    $spot = Spot::factory()->create();
    $capture = app(CaptureMediaCandidate::class);
    $first = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Verified.jpg',
        remoteUrl: 'https://upload.wikimedia.org/verified.jpg', rightsStatus: 'approved',
        healthStatus: 'active', checksum: str_repeat('b', 64), width: 1200, height: 800,
    ));
    $verifiedAt = $first->mediaAsset->last_verified_at->toAtomString();
    Queue::fake();
    $second = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Verified.jpg',
        remoteUrl: 'https://upload.wikimedia.org/verified.jpg', rightsStatus: 'pending',
        authoritativeEvidence: true, width: 2400, height: 1600,
    ));
    expect($second->mediaAsset->rights_status)->toBe('pending')
        ->and($second->mediaAsset->health_status)->toBe('active')
        ->and($second->mediaAsset->checksum)->toBe(str_repeat('b', 64))
        ->and($second->mediaAsset->width)->toBe(1200)
        ->and($second->mediaAsset->last_verified_at->toAtomString())->toBe($verifiedAt);
    Queue::assertNotPushed(ValidateMediaAssetJob::class);
});

test('a changed image URL clears old checksum and queues fresh validation', function () {
    Queue::fake();
    $spot = Spot::factory()->create();
    $capture = app(CaptureMediaCandidate::class);
    $first = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Changed.jpg',
        remoteUrl: 'https://upload.wikimedia.org/old.jpg', rightsStatus: 'approved',
        healthStatus: 'active', checksum: str_repeat('c', 64),
    ));
    Queue::fake();
    $second = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Changed.jpg',
        remoteUrl: 'https://upload.wikimedia.org/new.jpg', rightsStatus: 'approved', authoritativeEvidence: true,
    ));
    expect($second->mediaAsset->health_status)->toBe('pending')
        ->and($second->mediaAsset->checksum)->toBeNull();
    Queue::assertPushed(ValidateMediaAssetJob::class);
});

test('legacy metadata health with a recent timestamp still requires byte validation', function () {
    Queue::fake();
    $spot = Spot::factory()->create();
    $capture = app(CaptureMediaCandidate::class);
    $first = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Legacy.jpg',
        remoteUrl: 'https://upload.wikimedia.org/legacy.jpg', rightsStatus: 'approved',
        healthStatus: 'active', checksum: str_repeat('a', 40),
    ));
    Queue::fake();
    $second = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Legacy.jpg',
        remoteUrl: 'https://upload.wikimedia.org/legacy.jpg', rightsStatus: 'approved', authoritativeEvidence: true,
    ));
    expect($second->mediaAsset->health_status)->toBe('pending');
    Queue::assertPushed(ValidateMediaAssetJob::class);
});

test('an infrastructure retry preserves previously decoded bytes during authoritative refresh', function () {
    Queue::fake();
    $bytes = UploadedFile::fake()->image('photo.jpg', 800, 500)->getContent();
    Http::fake(['upload.wikimedia.org/*' => Http::response($bytes, 200, ['Content-Type' => 'image/jpeg'])]);
    $spot = Spot::factory()->create();
    $capture = app(CaptureMediaCandidate::class);
    $first = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Retry.jpg',
        remoteUrl: 'https://upload.wikimedia.org/retry.jpg', rightsStatus: 'approved',
    ));
    $validator = app(MediaAssetValidator::class);
    expect($validator->validate($first->mediaAsset))->toBe('active');
    $verifiedAt = $first->mediaAsset->fresh()->last_verified_at->toAtomString();
    $validator->recordInfrastructureFailure($first->mediaAsset, 'Worker stopped');
    Queue::fake();
    $second = $capture->execute($spot, new MediaCandidate(
        provider: 'wikimedia-commons', providerAssetId: 'File:Retry.jpg',
        remoteUrl: 'https://upload.wikimedia.org/retry.jpg', rightsStatus: 'approved', authoritativeEvidence: true,
    ));
    expect($second->mediaAsset->health_status)->toBe('active')
        ->and($second->mediaAsset->checksum)->toBe(hash('sha256', $bytes))
        ->and($second->mediaAsset->last_verified_at->toAtomString())->toBe($verifiedAt)
        ->and($second->mediaAsset->last_validation_outcome)->toBe('job_failed');
    Queue::assertPushed(ValidateMediaAssetJob::class);
});
