<?php

use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\Spot;
use App\Models\Venue;
use App\Support\EventOccurrencePresenter;
use Carbon\CarbonImmutable;

test('event cards never publish an unmanaged legacy place photograph', function () {
    $place = Spot::factory()->create(['photo_url' => 'https://media.example.test/unreviewed.jpg', 'photo_attribution' => 'Unverified credit']);
    $venue = Venue::create(['name' => 'Test venue', 'place_id' => $place->id]);
    $event = Event::factory()->create(['venue_id' => $venue->id]);
    $presenter = app(EventOccurrencePresenter::class);

    expect($presenter->present($event, CarbonImmutable::now(), null)['photo_url'])->toBeNull()
        ->and($presenter->photo($event))->toBe(['url' => null, 'attribution' => null, 'source_url' => null, 'license_url' => null]);
});

test('event media requires approved rights and active health', function (string $rights, string $health, bool $visible) {
    $place = Spot::factory()->create(['photo_url' => 'https://media.example.test/unreviewed.jpg']);
    $venue = Venue::create(['name' => 'Test venue', 'place_id' => $place->id]);
    $event = Event::factory()->create(['venue_id' => $venue->id]);
    $asset = MediaAsset::factory()->create([
        'remote_url' => 'https://media.example.test/managed.jpg',
        'attribution' => 'Reviewed credit',
        'source_page_url' => 'https://media.example.test/source',
        'rights_status' => $rights,
        'health_status' => $health,
    ]);
    $place->mediaAttachments()->create([
        'media_asset_id' => $asset->id, 'role' => 'hero',
        'match_status' => 'accepted', 'match_method' => 'manual_review',
        'match_evidence' => ['subject' => 'The linked event venue'], 'match_reviewed_at' => now(),
    ]);
    $presenter = app(EventOccurrencePresenter::class);
    $expected = $visible ? $asset->remote_url : null;

    expect($presenter->present($event, CarbonImmutable::now(), null)['photo_url'])->toBe($expected)
        ->and($presenter->photo($event)['url'])->toBe($expected);
})->with([
    ['pending', 'active', false],
    ['approved', 'pending', false],
    ['approved', 'broken', false],
    ['approved', 'active', true],
]);
