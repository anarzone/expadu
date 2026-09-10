<?php

use App\Models\MediaAsset;
use App\Models\Review;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Places\PlaceIdentityAudit;
use Illuminate\Support\Facades\Artisan;

function identityAuditSpot(array $attributes = []): Spot
{
    return Spot::factory()->create([
        'name' => 'Testgarten', 'category' => 'park', 'source' => null, 'source_id' => null,
        'lat' => 50.95, 'lng' => 6.95, 'is_active' => true, 'is_recommendable' => true,
        ...$attributes,
    ]);
}

test('identity audit reports candidate evidence without modifying either place', function () {
    $legacy = identityAuditSpot();
    $current = identityAuditSpot(['source' => 'osm', 'source_id' => 'way/42', 'lat' => 50.95001]);
    $before = Spot::query()->orderBy('id')->get()->toArray();

    expect(Artisan::call('places:audit-identities'))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['auto_merge_allowed'])->toBeFalse()
        ->and($report['summary']['candidate_pairs'])->toBe(1)
        ->and($report['candidates'][0]['legacy']['id'])->toBe($legacy->id)
        ->and($report['candidates'][0]['current']['source_id'])->toBe('way/42')
        ->and($report['candidates'][0]['current']['id'])->toBe($current->id)
        ->and($report['candidates'][0]['review_required'])->toBeTrue()
        ->and($report['candidates'][0]['distance_m'])->toBeLessThan(10)
        ->and(Spot::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('identity audit retains all ambiguous matches and exposes eligibility disagreement', function () {
    $legacy = identityAuditSpot(['name' => 'Tennisplatz', 'category' => 'tennis']);
    identityAuditSpot(['name' => 'Tennisplatz', 'category' => 'tennis', 'source' => 'osm', 'source_id' => 'node/42', 'is_recommendable' => false]);
    identityAuditSpot(['name' => 'Tennisplatz', 'category' => 'tennis', 'source' => 'osm', 'source_id' => 'way/42']);

    $report = app(PlaceIdentityAudit::class)->report();

    expect($report['summary']['candidate_pairs'])->toBe(2)
        ->and($report['summary']['matched_legacy_records'])->toBe(1)
        ->and($report['candidates'][0]['flags'])->toContain('ambiguous_match', 'generic_name', 'eligibility_disagreement')
        ->and($report['candidates'][1]['flags'])->toContain('ambiguous_match', 'generic_name')
        ->and($report['candidates'][0]['legacy']['id'])->toBe($legacy->id);
});

test('identity audit does not confuse activities distant places or sourced identities with legacy duplicates', function () {
    identityAuditSpot();
    identityAuditSpot(['source' => 'osm', 'source_id' => 'node/1', 'category' => 'basketball']);
    identityAuditSpot(['source' => 'osm', 'source_id' => 'node/2', 'lat' => 50.96]);
    identityAuditSpot(['source' => 'osm', 'source_id' => 'node/3', 'name' => 'Other garden']);
    identityAuditSpot(['source' => 'osm', 'source_id' => 'way/3', 'name' => 'Other garden']);

    expect(app(PlaceIdentityAudit::class)->report()['candidates'])->toBe([]);
});

test('identity audit exposes reference counts and photo gates without exporting user content', function () {
    $legacy = identityAuditSpot();
    identityAuditSpot(['source' => 'osm', 'source_id' => 'node/4']);
    identityAuditSpot(['parent_spot_id' => $legacy->id, 'category' => 'basketball']);
    Review::factory()->create(['spot_id' => $legacy->id, 'body' => 'Private review text']);
    SpotFeedback::factory()->create(['spot_id' => $legacy->id]);
    $legacy->mediaAttachments()->create([
        'media_asset_id' => MediaAsset::factory()->create(['rights_status' => 'pending', 'health_status' => 'active'])->id,
        'role' => 'hero', 'is_manually_locked' => true,
    ]);

    $report = app(PlaceIdentityAudit::class)->report();
    $record = $report['candidates'][0]['legacy'];

    expect($record['references']['reviews'])->toBe(1)
        ->and($record['references']['feedback'])->toBe(1)
        ->and($record['references']['children'])->toBe(1)
        ->and($record['media'][0]['rights_status'])->toBe('pending')
        ->and($record['media'][0]['is_manually_locked'])->toBeTrue()
        ->and($record['published_media_count'])->toBe(0)
        ->and(json_encode($report))->not->toContain('Private review text', 'user_id');
});

test('identity audit rejects an unsafe radius', function (string $radius) {
    $this->artisan('places:audit-identities', ['--radius' => $radius])->assertFailed();
})->with(['0', '-1', '101', 'not-a-number']);
