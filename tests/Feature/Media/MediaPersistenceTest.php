<?php

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\MediaAttachment;
use App\Models\Spot;
use App\Models\Venue;
use Illuminate\Support\Facades\Schema;

test('media persistence stores reusable assets and polymorphic attachments', function () {
    expect(Schema::hasColumns('media_assets', [
        'id',
        'type',
        'provider',
        'provider_asset_id',
        'source_key',
        'remote_url',
        'source_page_url',
        'author',
        'attribution',
        'license_code',
        'license_url',
        'mime_type',
        'width',
        'height',
        'checksum',
        'rights_status',
        'health_status',
        'failure_count',
        'last_error',
        'last_seen_at',
        'last_verified_at',
        'metadata',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('media_attachments', [
            'id',
            'media_asset_id',
            'mediable_type',
            'mediable_id',
            'role',
            'priority',
            'is_primary',
            'is_manually_locked',
        ]))->toBeTrue();
});

test('media asset and attachment models are available', function () {
    expect(class_exists('App\\Models\\MediaAsset'))->toBeTrue()
        ->and(class_exists('App\\Models\\MediaAttachment'))->toBeTrue();
});

test('media models expose the required relationships and publication scopes', function () {
    expect(method_exists(Event::class, 'mediaAttachments'))->toBeTrue()
        ->and(method_exists(Spot::class, 'mediaAttachments'))->toBeTrue()
        ->and(method_exists(Venue::class, 'mediaAttachments'))->toBeTrue()
        ->and(method_exists(MediaAttachment::class, 'mediaAsset'))->toBeTrue()
        ->and(method_exists(MediaAttachment::class, 'mediable'))->toBeTrue()
        ->and(method_exists(MediaAsset::class, 'scopePublished'))->toBeTrue();
});

test('approved active assets attach to an event and are publication eligible', function () {
    $event = Event::factory()->create();
    $asset = MediaAsset::factory()->approved()->create();
    MediaAsset::factory()->create();

    $attachment = $event->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'poster',
        'priority' => 10,
        'is_primary' => true,
    ]);

    expect($attachment->mediaAsset->is($asset))->toBeTrue()
        ->and($attachment->mediable->is($event))->toBeTrue()
        ->and($attachment->is_primary)->toBeTrue()
        ->and(MediaAsset::query()->published()->sole()->is($asset))->toBeTrue();
});

test('deleting a media owner removes its polymorphic attachments', function () {
    $owners = [
        Event::factory()->create(),
        Spot::factory()->create(),
        Venue::query()->create(['name' => 'Temporary venue']),
    ];

    foreach ($owners as $owner) {
        $owner->mediaAttachments()->create([
            'media_asset_id' => MediaAsset::factory()->create()->id,
            'role' => 'hero',
        ]);
        $owner->delete();
    }

    expect(MediaAttachment::query()->count())->toBe(0)
        ->and(MediaAsset::query()->count())->toBe(3);
});
