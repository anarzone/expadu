<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\CaptureMediaCandidate;
use App\Media\MediaCandidate;
use App\Models\Event;
use App\Models\MediaAsset;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Queue;

test('media candidate capture boundary is available', function () {
    expect(class_exists('App\\Media\\MediaCandidate'))->toBeTrue()
        ->and(class_exists('App\\Media\\CaptureMediaCandidate'))->toBeTrue()
        ->and(class_exists('App\\Jobs\\ValidateMediaAssetJob'))->toBeTrue();
});

test('media capture and validation expose queue-safe interfaces', function () {
    expect(method_exists(MediaCandidate::class, '__construct'))->toBeTrue()
        ->and(method_exists(CaptureMediaCandidate::class, 'execute'))->toBeTrue()
        ->and(is_subclass_of(
            ValidateMediaAssetJob::class,
            ShouldQueue::class,
        ))->toBeTrue()
        ->and(is_subclass_of(
            ValidateMediaAssetJob::class,
            ShouldBeUnique::class,
        ))->toBeTrue()
        ->and(method_exists(ValidateMediaAssetJob::class, 'uniqueId'))->toBeTrue()
        ->and(method_exists(ValidateMediaAssetJob::class, 'middleware'))->toBeTrue()
        ->and(method_exists(ValidateMediaAssetJob::class, 'failed'))->toBeTrue();

    $job = new ValidateMediaAssetJob(MediaAsset::factory()->create());
    expect($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0])->toBeInstanceOf(RateLimited::class)
        ->and($job->tries)->toBe(0)
        ->and($job->timeout)->toBeGreaterThan(45)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->addHours(23)->getTimestamp());
});

test('a source candidate creates one attached asset and queues validation after commit', function () {
    $event = Event::factory()->create();
    Queue::fake();

    $attachment = app(CaptureMediaCandidate::class)->execute($event, new MediaCandidate(
        provider: 'stadt-koeln',
        providerAssetId: '/mediaasset/poster.jpg',
        remoteUrl: 'https://www.stadt-koeln.de/mediaasset/poster.jpg',
        sourcePageUrl: 'https://www.stadt-koeln.de/events/42',
        role: 'poster',
        priority: 10,
        isPrimary: true,
    ));

    $asset = MediaAsset::query()->first();

    expect($attachment)->not->toBeNull()
        ->and(MediaAsset::query()->count())->toBe(1)
        ->and($asset?->source_key)->toBe(hash('sha256', 'stadt-koeln|/mediaasset/poster.jpg'))
        ->and($asset?->rights_status)->toBe('pending')
        ->and($attachment?->mediable->is($event))->toBeTrue()
        ->and($attachment?->role)->toBe('poster')
        ->and($attachment?->is_primary)->toBeTrue();

    Queue::assertPushed(
        ValidateMediaAssetJob::class,
        fn (ValidateMediaAssetJob $job): bool => $job->asset->is($asset) && $job->afterCommit === true,
    );
});

test('repeated source candidates update one asset instead of duplicating media', function () {
    $event = Event::factory()->create();
    Queue::fake();
    $capture = app(CaptureMediaCandidate::class);

    $first = $capture->execute($event, new MediaCandidate(
        provider: 'koeln-de',
        providerAssetId: 'image-42',
        remoteUrl: 'https://www.koeln.de/images/old.jpg',
        role: 'poster',
    ));
    $second = $capture->execute($event, new MediaCandidate(
        provider: 'koeln-de',
        providerAssetId: 'image-42',
        remoteUrl: 'https://www.koeln.de/images/new.jpg',
        sourcePageUrl: 'https://www.koeln.de/event/42',
        role: 'poster',
        priority: 20,
    ));

    expect(MediaAsset::query()->count())->toBe(1)
        ->and($event->mediaAttachments()->count())->toBe(1)
        ->and($second?->is($first))->toBeTrue()
        ->and($second?->mediaAsset->remote_url)->toBe('https://www.koeln.de/images/new.jpg')
        ->and($second?->priority)->toBe(20);
});

test('a lower-confidence rediscovery cannot downgrade verified publishing evidence', function () {
    $event = Event::factory()->create();
    Queue::fake();
    $capture = app(CaptureMediaCandidate::class);

    $verified = $capture->execute($event, new MediaCandidate(
        provider: 'wikimedia-commons',
        providerAssetId: 'File:Verified.jpg',
        remoteUrl: 'https://upload.wikimedia.org/verified-thumb.jpg',
        sourcePageUrl: 'https://commons.wikimedia.org/wiki/File:Verified.jpg',
        rightsStatus: 'approved',
        healthStatus: 'active',
        author: 'Jane Doe',
        attribution: 'Jane Doe · CC BY 4.0 · Wikimedia Commons',
        licenseCode: 'CC BY 4.0',
        width: 1200,
        height: 800,
    ));

    $rediscovered = $capture->execute($event, new MediaCandidate(
        provider: 'wikimedia-commons',
        providerAssetId: 'File:Verified.jpg',
        remoteUrl: 'https://commons.wikimedia.org/wiki/Special:FilePath/Verified.jpg',
        rightsStatus: 'pending',
        healthStatus: 'pending',
        shouldValidate: false,
    ));

    expect($rediscovered?->is($verified))->toBeTrue()
        ->and($rediscovered?->mediaAsset->remote_url)->toBe('https://upload.wikimedia.org/verified-thumb.jpg')
        ->and($rediscovered?->mediaAsset->rights_status)->toBe('approved')
        ->and($rediscovered?->mediaAsset->health_status)->toBe('active')
        ->and($rediscovered?->mediaAsset->author)->toBe('Jane Doe')
        ->and($rediscovered?->mediaAsset->license_code)->toBe('CC BY 4.0');
});

test('a stale active asset is queued for health revalidation on rediscovery', function () {
    $event = Event::factory()->create();
    Queue::fake();
    $capture = app(CaptureMediaCandidate::class);
    $attachment = $capture->execute($event, new MediaCandidate(
        provider: 'stadt-koeln',
        providerAssetId: '/mediaasset/stale.jpg',
        remoteUrl: 'https://www.stadt-koeln.de/mediaasset/stale.jpg',
        healthStatus: 'active',
    ));
    Queue::fake();
    $attachment?->mediaAsset->update(['last_verified_at' => now()->subDays(8)]);

    $capture->execute($event, new MediaCandidate(
        provider: 'stadt-koeln',
        providerAssetId: '/mediaasset/stale.jpg',
        remoteUrl: 'https://www.stadt-koeln.de/mediaasset/stale.jpg',
    ));

    Queue::assertPushed(
        ValidateMediaAssetJob::class,
        fn (ValidateMediaAssetJob $job): bool => $job->asset->is($attachment?->mediaAsset),
    );
});

test('automatic capture cannot replace a manually locked primary attachment', function () {
    $event = Event::factory()->create();
    $lockedAsset = MediaAsset::factory()->approved()->create();
    $locked = $event->mediaAttachments()->create([
        'media_asset_id' => $lockedAsset->id,
        'role' => 'poster',
        'priority' => 1,
        'is_primary' => true,
        'is_manually_locked' => true,
    ]);
    Queue::fake();

    $automatic = app(CaptureMediaCandidate::class)->execute($event, new MediaCandidate(
        provider: 'stadt-koeln',
        providerAssetId: 'automatic',
        remoteUrl: 'https://www.stadt-koeln.de/mediaasset/automatic.jpg',
        role: 'poster',
        priority: 5,
        isPrimary: true,
    ));

    expect($locked->fresh()->is_primary)->toBeTrue()
        ->and($automatic?->is_primary)->toBeFalse();
});

test('unsafe or malformed candidate URLs are ignored without queueing work', function (string $url) {
    $event = Event::factory()->create();
    Queue::fake();

    $attachment = app(CaptureMediaCandidate::class)->execute($event, new MediaCandidate(
        provider: 'stadt-koeln',
        remoteUrl: $url,
    ));

    expect($attachment)->toBeNull()
        ->and(MediaAsset::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'plain HTTP' => 'http://www.stadt-koeln.de/image.jpg',
    'not a URL' => 'not-a-url',
    'local file' => 'file:///tmp/image.jpg',
]);

test('unknown-host source media is retained for review without scheduling an unsafe fetch', function () {
    $event = Event::factory()->create();
    Queue::fake();

    $attachment = app(CaptureMediaCandidate::class)->execute($event, new MediaCandidate(
        provider: 'osm-image',
        remoteUrl: 'https://photos.example.org/place.jpg',
        sourcePageUrl: 'https://www.openstreetmap.org/node/42',
    ));

    expect($attachment)->not->toBeNull()
        ->and($attachment?->mediaAsset->rights_status)->toBe('pending')
        ->and($attachment?->mediaAsset->health_status)->toBe('pending');

    Queue::assertNothingPushed();
});
