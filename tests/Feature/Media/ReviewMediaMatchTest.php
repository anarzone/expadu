<?php

use App\Media\ReviewMediaMatch;
use App\Models\MediaAsset;
use App\Models\MediaMatchReview;
use App\Models\Spot;
use Illuminate\Support\Facades\Artisan;

function mediaAttachmentForReview(): array
{
    $spot = Spot::factory()->create([
        'name' => 'Reviewed park',
        'category' => 'park',
        'source' => 'osm',
        'source_id' => 'way/4455',
        'lat' => 50.94,
        'lng' => 6.95,
    ]);
    $asset = MediaAsset::factory()->approved()->create([
        'provider' => 'wikimedia-commons',
        'provider_asset_id' => 'File:Reviewed park.jpg',
        'source_key' => hash('sha256', 'wikimedia-commons|File:Reviewed park.jpg'),
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Reviewed_park.jpg',
        'remote_url' => 'https://upload.wikimedia.org/reviewed-park.jpg',
        'attribution' => 'Photographer · CC BY 4.0',
    ]);
    $attachment = $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'is_manually_locked' => true,
    ]);

    return [$spot, $asset, $attachment];
}

test('media match previews are deterministic and contain stable owner and asset identity', function () {
    [$spot, $asset, $attachment] = mediaAttachmentForReview();
    $service = app(ReviewMediaMatch::class);

    $first = $service->preview($attachment->id, 'accepted', 'manual_review');
    $second = $service->preview($attachment->id, 'accepted', 'manual_review');

    expect($first)->toBe($second)
        ->and($first['attachment_id'])->toBe($attachment->id)
        ->and($first['decision'])->toBe('accepted')
        ->and($first['snapshot']['owner'])->toMatchArray([
            'type' => Spot::class,
            'id' => $spot->id,
            'source' => 'osm',
            'source_id' => 'way/4455',
        ])
        ->and($first['snapshot']['asset'])->toMatchArray([
            'id' => $asset->id,
            'provider' => 'wikimedia-commons',
            'provider_asset_id' => 'File:Reviewed park.jpg',
        ])
        ->and($first['fingerprint'])->toMatch('/^[a-f0-9]{64}$/');
});

test('applying a media match records evidence without changing manual priority', function () {
    [, , $attachment] = mediaAttachmentForReview();
    $service = app(ReviewMediaMatch::class);
    $preview = $service->preview($attachment->id, 'accepted', 'manual_review');

    $service->apply(
        $attachment->id,
        'accepted',
        'manual_review',
        $preview['fingerprint'],
        'The official Commons file page and mapped subject confirm this exact park.',
        'reviewer@example.test',
    );

    $attachment->refresh();
    $history = MediaMatchReview::query()->sole();
    expect($attachment->match_status)->toBe('accepted')
        ->and($attachment->match_method)->toBe('manual_review')
        ->and($attachment->match_evidence)->toMatchArray([
            'reviewer' => 'reviewer@example.test',
            'evidence' => 'The official Commons file page and mapped subject confirm this exact park.',
        ])
        ->and($attachment->match_reviewed_at)->not->toBeNull()
        ->and($attachment->is_manually_locked)->toBeTrue()
        ->and($history->previous_status)->toBe('pending')
        ->and($history->new_status)->toBe('accepted')
        ->and($history->fingerprint)->toBe($preview['fingerprint'])
        ->and($history->snapshot)->toEqual($preview['snapshot']);
});

test('media match application rejects stale previews and incomplete review evidence', function () {
    [, $asset, $attachment] = mediaAttachmentForReview();
    $service = app(ReviewMediaMatch::class);
    $preview = $service->preview($attachment->id, 'rejected', 'manual_review');

    $asset->update(['provider_asset_id' => 'File:Different subject.jpg']);

    expect(fn () => $service->apply(
        $attachment->id,
        'rejected',
        'manual_review',
        $preview['fingerprint'],
        'The reviewed file depicts a different physical destination.',
        'reviewer@example.test',
    ))->toThrow(DomainException::class, 'changed after preview')
        ->and(fn () => $service->apply(
            $attachment->id,
            'accepted',
            'manual_review',
            $service->preview($attachment->id, 'accepted', 'manual_review')['fingerprint'],
            'Too short',
            'reviewer@example.test',
        ))->toThrow(DomainException::class)
        ->and(MediaMatchReview::query()->count())->toBe(0);
});

test('media match command previews by default and applies only the reviewed fingerprint', function () {
    [, , $attachment] = mediaAttachmentForReview();
    $preview = app(ReviewMediaMatch::class)->preview($attachment->id, 'accepted', 'manual_review');

    expect(Artisan::call('media:review-match', [
        'attachment' => $attachment->id,
        'decision' => 'accepted',
    ]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['applied'])->toBeFalse();

    $this->artisan('media:review-match', [
        'attachment' => $attachment->id,
        'decision' => 'accepted',
        '--apply' => true,
        '--fingerprint' => 'wrong',
        '--evidence' => 'The official source confirms this exact photographed destination.',
        '--actor' => 'reviewer@example.test',
    ])->assertFailed();

    $this->artisan('media:review-match', [
        'attachment' => $attachment->id,
        'decision' => 'accepted',
        '--apply' => true,
        '--fingerprint' => $preview['fingerprint'],
        '--evidence' => 'The official source confirms this exact photographed destination.',
        '--actor' => 'reviewer@example.test',
    ])->assertSuccessful();

    expect($attachment->refresh()->match_status)->toBe('accepted');
});
