<?php

use App\Media\PlaceMediaCoverageAudit;
use App\Models\MediaAsset;
use App\Models\Spot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

test('place media coverage reports destination and asset states across alias families', function () {
    DB::table('veedels')->insert([
        'name' => 'Audit Veedel',
        'bezirk' => 'Audit Bezirk',
    ]);
    $legacyOnly = Spot::factory()->create([
        'name' => 'Legacy only park',
        'category' => 'park',
        'veedel' => 'Audit Veedel',
        'photo_url' => 'https://www.stadt-koeln.de/mediaasset/legacy.jpg',
    ]);
    $selected = Spot::factory()->create([
        'name' => 'Selected park',
        'category' => 'park',
        'veedel' => 'Audit Veedel',
    ]);
    $alias = Spot::factory()->create([
        'name' => 'Selected park alias',
        'category' => 'park',
        'veedel' => 'Audit Veedel',
    ]);
    $alias->forceFill(['canonical_spot_id' => $selected->id])->save();
    $publishedAsset = MediaAsset::factory()->approved()->create([
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Selected.jpg',
        'attribution' => 'Example · CC BY 4.0',
    ]);
    $alias->mediaAttachments()->create([
        'media_asset_id' => $publishedAsset->id,
        'role' => 'hero',
        'is_manually_locked' => true,
        'match_status' => 'accepted',
        'match_method' => 'manual_review',
        'match_evidence' => ['review' => 'Exact destination'],
        'match_reviewed_at' => now(),
    ]);
    $pending = Spot::factory()->create([
        'name' => 'Pending park',
        'category' => 'park',
        'veedel' => 'Audit Veedel',
    ]);
    $pendingAsset = MediaAsset::factory()->create([
        'rights_status' => 'pending',
        'health_status' => 'broken',
    ]);
    $pending->mediaAttachments()->create([
        'media_asset_id' => $pendingAsset->id,
        'role' => 'hero',
        'match_status' => 'pending',
        'match_method' => 'commons_geosearch',
        'match_evidence' => ['reason' => 'Nearby candidate only'],
    ]);

    $report = app(PlaceMediaCoverageAudit::class)->report(candidateLimit: 10);

    expect($report['destinations'])->toMatchArray([
        'total' => 3,
        'no_candidate' => 1,
        'legacy_only_url' => 1,
        'approved_active_selected' => 1,
        'manual_selection' => 1,
        'rights_pending' => 1,
        'health_broken' => 1,
        'relevance_unknown' => 1,
    ])->and($report['assets'])->toMatchArray([
        'total' => 2,
        'rights_approved' => 1,
        'rights_pending' => 1,
        'health_active' => 1,
        'health_broken' => 1,
    ])->and($report['by_category']['park']['total'])->toBe(3)
        ->and($report['by_bezirk']['Audit Bezirk']['total'])->toBe(3)
        ->and($report['candidate_audit'])->toHaveCount(2)
        ->and(collect($report['candidate_audit'])->firstWhere('spot_id', $selected->id)['selected'])->toBeTrue()
        ->and($report['legacy_audit'][0])->toMatchArray([
            'spot_id' => $legacyOnly->id,
            'host' => 'www.stadt-koeln.de',
            'reason' => 'unmanaged_legacy_pointer',
        ]);
});

test('place media coverage command is read only and emits bounded JSON', function () {
    Spot::factory()->create([
        'name' => 'Audited park',
        'category' => 'park',
        'photo_url' => 'https://www.stadt-koeln.de/mediaasset/legacy.jpg',
    ]);

    expect(Artisan::call('media:audit-place-coverage', ['--candidates' => 0]))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($report['destinations']['total'])->toBe(1)
        ->and($report['candidate_audit'])->toBe([])
        ->and(Spot::query()->sole()->photo_url)->not->toBeNull();
});
