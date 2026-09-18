<?php

use App\Media\PublishedMediaSelector;
use App\Models\Event;
use App\Models\MediaAsset;
use App\Models\Spot;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->actingAs(User::factory()->onboarded()->create());
});

test('the places API never publishes an unreviewed legacy photo URL', function () {
    $spot = Spot::factory()->create([
        'category' => 'park',
        'photo_url' => 'https://unreviewed.example.test/legacy.jpg',
        'photo_attribution' => 'Unknown legacy source',
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null)
        ->assertJsonPath('data.photo_attribution', null)
        ->assertJsonPath('data.photo_source_url', null)
        ->assertJsonPath('data.photo_license_url', null);
});

test('the places API never publishes an approved but broken managed asset', function () {
    $spot = Spot::factory()->create([
        'category' => 'park',
        'photo_url' => 'https://unreviewed.example.test/legacy.jpg',
    ]);
    $asset = MediaAsset::factory()->approved()->create([
        'health_status' => 'broken',
        'remote_url' => 'https://images.example.test/broken.jpg',
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'is_primary' => true,
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});

test('manual priority never publishes an asset whose rights are not approved', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $asset = MediaAsset::factory()->create([
        'rights_status' => 'pending',
        'health_status' => 'active',
        'remote_url' => 'https://images.example.test/unapproved.jpg',
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'is_primary' => true,
        'is_manually_locked' => true,
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});

test('approved active media remains hidden while its place match is pending', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $asset = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/pending-match.jpg',
        'source_page_url' => 'https://source.example.test/pending-match',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'is_primary' => true,
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});

test('accepted place-specific evidence publishes an approved active asset', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $asset = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/accepted.jpg',
        'source_page_url' => 'https://source.example.test/accepted',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $attachment = $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'is_primary' => true,
    ]);
    DB::table('media_attachments')->where('id', $attachment->id)->update([
        'match_status' => 'accepted',
        'match_method' => 'reviewed_source',
        'match_evidence' => json_encode(['source_url' => 'https://source.example.test/accepted'], JSON_THROW_ON_ERROR),
        'match_reviewed_at' => now(),
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', 'https://images.example.test/accepted.jpg')
        ->assertJsonPath('data.photo_attribution', 'Photo by Example · CC BY 4.0')
        ->assertJsonPath('data.photo_source_url', 'https://source.example.test/accepted');
});

test('a manual lock changes priority but cannot override rejected match evidence', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $rejected = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/rejected.jpg',
        'source_page_url' => 'https://source.example.test/rejected',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $accepted = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/accepted-second.jpg',
        'source_page_url' => 'https://source.example.test/accepted-second',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $rejectedAttachment = $spot->mediaAttachments()->create([
        'media_asset_id' => $rejected->id,
        'role' => 'hero',
        'is_manually_locked' => true,
    ]);
    $acceptedAttachment = $spot->mediaAttachments()->create([
        'media_asset_id' => $accepted->id,
        'role' => 'hero',
        'is_primary' => true,
    ]);
    DB::table('media_attachments')->where('id', $rejectedAttachment->id)->update([
        'match_status' => 'rejected',
        'match_method' => 'manual_review',
        'match_evidence' => json_encode(['reason' => 'Different place'], JSON_THROW_ON_ERROR),
        'match_reviewed_at' => now(),
    ]);
    DB::table('media_attachments')->where('id', $acceptedAttachment->id)->update([
        'match_status' => 'accepted',
        'match_method' => 'manual_review',
        'match_evidence' => json_encode(['source_url' => 'https://source.example.test/accepted-second'], JSON_THROW_ON_ERROR),
        'match_reviewed_at' => now(),
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', 'https://images.example.test/accepted-second.jpg');
});

test('a manually locked broken asset falls back to the next publishable photo', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $broken = MediaAsset::factory()->approved()->create([
        'health_status' => 'broken',
        'remote_url' => 'https://images.example.test/locked-broken.jpg',
        'source_page_url' => 'https://source.example.test/locked-broken',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $fallback = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/healthy-fallback.jpg',
        'source_page_url' => 'https://source.example.test/healthy-fallback',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    foreach ([[$broken, true], [$fallback, false]] as [$asset, $locked]) {
        $spot->mediaAttachments()->create([
            'media_asset_id' => $asset->id,
            'role' => 'hero',
            'is_manually_locked' => $locked,
            'match_status' => 'accepted',
            'match_method' => 'manual_review',
            'match_evidence' => ['review' => 'Correct place'],
            'match_reviewed_at' => now(),
        ]);
    }

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', 'https://images.example.test/healthy-fallback.jpg');
});

test('accepted match evidence does not publish media without source attribution', function () {
    $spot = Spot::factory()->create(['category' => 'park']);
    $asset = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/unattributed.jpg',
        'source_page_url' => null,
        'attribution' => null,
    ]);
    $attachment = $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
    ]);
    DB::table('media_attachments')->where('id', $attachment->id)->update([
        'match_status' => 'accepted',
        'match_method' => 'manual_review',
        'match_evidence' => json_encode(['review' => 'Correct subject'], JSON_THROW_ON_ERROR),
        'match_reviewed_at' => now(),
    ]);

    $this->getJson("/api/places/{$spot->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});

test('an accepted attachment without concrete match evidence remains unpublished', function () {
    $venue = Venue::create(['name' => 'Reviewed venue']);
    $asset = MediaAsset::factory()->approved()->create();
    $venue->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'match_status' => 'accepted',
        'match_method' => 'manual_review',
        'match_evidence' => [],
        'match_reviewed_at' => now(),
    ]);

    expect(app(PublishedMediaSelector::class)->select($venue->fresh(), 'hero'))->toBeNull();
});

test('an event direct poster keeps its existing asset publication contract', function () {
    $event = Event::factory()->create();
    $asset = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://upload.wikimedia.org/event-poster.jpg',
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Event_poster.jpg',
        'attribution' => 'Example · CC BY 4.0',
    ]);
    $event->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'poster',
    ]);

    expect(app(PublishedMediaSelector::class)->select($event->fresh(), 'poster')?->id)->toBe($asset->id);
});

test('a destination parent photo is not inherited by a distinct child facility', function () {
    $parent = Spot::factory()->create(['name' => 'Sports park', 'category' => 'park']);
    $child = Spot::factory()->create([
        'name' => 'Tennis court',
        'category' => 'tennis',
        'parent_spot_id' => $parent->id,
    ]);
    $asset = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://images.example.test/parent-only.jpg',
        'source_page_url' => 'https://source.example.test/parent-only',
        'attribution' => 'Photo by Example · CC BY 4.0',
    ]);
    $parent->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'match_status' => 'accepted',
        'match_method' => 'manual_review',
        'match_evidence' => ['review' => 'The image shows the parent destination'],
        'match_reviewed_at' => now(),
    ]);

    $this->getJson("/api/places/{$child->id}")
        ->assertOk()
        ->assertJsonPath('data.photo_url', null);
});
