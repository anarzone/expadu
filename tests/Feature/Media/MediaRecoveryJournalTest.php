<?php

use App\Media\PublishedMediaSelector;
use App\Models\MediaAsset;
use App\Models\Spot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $file = base_path('docs/places/production-release/MediaRecoveryJournal.php');
    if (is_file($file)) {
        require_once $file;
    }
    Queue::fake();
    Http::preventStrayRequests();
});

function recoveryPhotoFixture(int $number = 1, bool $healthy = true): array
{
    $image = imagecreatetruecolor(500, 300);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    $file = 'Example_'.$number.'.png';
    $spot = Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/'.(1000 + $number), 'category' => 'park', 'tags' => ['wikimedia_commons' => 'File:'.$file]]);
    $asset = MediaAsset::factory()->approved()->create(['provider' => 'wikimedia-commons', 'provider_asset_id' => 'File:'.$file, 'source_key' => hash('sha256', 'wikimedia-commons|File:'.$file), 'remote_url' => 'https://upload.wikimedia.org/old-'.$file]);
    $attachment = $spot->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero', 'priority' => 17, 'is_primary' => true]);
    $url = 'https://upload.wikimedia.org/'.$file;
    Http::fake([$url => $healthy ? Http::response($bytes, 200, ['Content-Type' => 'image/png']) : Http::response('unavailable', 404)]);
    $record = ['source' => 'osm', 'source_id' => $spot->source_id, 'spot_id' => $spot->id, 'asset_id' => $asset->id, 'attachment_id' => $attachment->id, 'commons_file' => $file,
        'method' => 'osm_wikimedia_commons_tag', 'reference' => 'File:'.$file, 'checked_at' => now()->toIso8601String(), 'proof_sha256' => hash('sha256', 'proof-'.$number), 'checksum' => hash('sha256', $bytes),
        'metadata' => ['remote_url' => $url, 'source_page_url' => 'https://commons.wikimedia.org/wiki/File:'.$file, 'author' => 'Example photographer', 'attribution' => 'Example photographer · CC BY 4.0 · Wikimedia Commons', 'license_code' => 'CC BY 4.0', 'license_url' => 'https://creativecommons.org/licenses/by/4.0/', 'rights_status' => 'approved', 'health_status' => 'pending', 'mime_type' => 'image/png', 'width' => 500, 'height' => 300, 'checksum' => null]];

    return [$record, $spot, $asset, $attachment];
}

function recoveryPhotoContext(array $records): array
{
    return ['database' => DB::selectOne('select current_database() as name')->name, 'package_sha256' => MediaRecoveryJournal::hash($records), 'application_sha256' => hash('sha256', 'test-candidate'), 'importer_sha256' => hash_file('sha256', base_path('docs/places/production-release/MediaRecoveryJournal.php'))];
}

test('media recovery journals apply replay and restart recovery while retaining audit history', function () {
    [$record, $spot, $asset, $attachment] = recoveryPhotoFixture();
    $service = new MediaRecoveryJournal;
    $records = [$record];
    $context = recoveryPhotoContext($records);
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    $receipt = $service->apply($id, $context, $records, $before, 'test-reviewer');
    expect(app(PublishedMediaSelector::class)->select($spot->fresh(), 'hero')->id)->toBe($asset->id)
        ->and($attachment->fresh()->priority)->toBe(17)
        ->and(DB::table('media_match_reviews')->count())->toBe(1)
        ->and(DB::table('media_validation_attempts')->count())->toBe(1);
    Http::assertSentCount(1);
    expect($service->apply($id, $context, $records, $before, 'test-reviewer'))->toBe($receipt);
    Http::assertSentCount(1);
    $recovery = (new MediaRecoveryJournal)->recover($id, $context, 'recovery-reviewer', 'Rehearsal restoring the original publication state.');
    expect($attachment->fresh()->match_status)->toBe('pending')
        ->and($asset->fresh()->remote_url)->toBe('https://upload.wikimedia.org/old-'.$record['commons_file'])
        ->and(DB::table('media_match_reviews')->count())->toBe(1)
        ->and(DB::table('media_validation_attempts')->count())->toBe(1)
        ->and((new MediaRecoveryJournal)->recover($id, $context, 'recovery-reviewer', 'Rehearsal restoring the original publication state.'))->toBe($recovery);
    expect(fn () => $service->apply($id, $context, $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
    Http::assertSentCount(1);
});

test('media recovery rejects target identity and source drift before any mutation', function (string $change) {
    [$record, $spot] = recoveryPhotoFixture();
    $service = new MediaRecoveryJournal;
    $before = $service->snapshot([$spot->id]);
    if ($change === 'source') {
        $spot->update(['tags' => ['wikimedia_commons' => 'File:Other.png']]);
    } else {
        $record['spot_id']++;
    }
    $records = [$record];
    $state = $service->snapshot([$spot->id]);
    expect(fn () => $service->apply((string) Str::uuid(), recoveryPhotoContext($records), $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($state);
    Http::assertNothingSent();
})->with(['source', 'target']);

test('a failed image rolls back the entire media batch and journal', function () {
    [$one, $spot] = recoveryPhotoFixture();
    [$two, $other] = recoveryPhotoFixture(2, false);
    $service = new MediaRecoveryJournal;
    $before = $service->snapshot([$spot->id, $other->id]);
    $records = [$one, $two];
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, recoveryPhotoContext($records), $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id, $other->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
});

test('recovery refuses later media edits and preserves the changed state', function () {
    [$record, $spot, $asset] = recoveryPhotoFixture();
    $service = new MediaRecoveryJournal;
    $records = [$record];
    $context = recoveryPhotoContext($records);
    $id = (string) Str::uuid();
    $service->apply($id, $context, $records, $service->snapshot([$spot->id]), 'test-reviewer');
    $asset->refresh()->update(['attribution' => 'A later reviewed attribution']);
    $changed = $service->snapshot([$spot->id]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Attempt recovery after someone changed attribution.'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($changed);
});

test('shared images are validated once and sibling attachment priorities stay intact', function () {
    [$one, $spot, $asset] = recoveryPhotoFixture();
    $other = Spot::factory()->create(['source' => 'osm', 'source_id' => 'way/1002', 'category' => 'park', 'tags' => ['wikimedia_commons' => $one['reference']]]);
    $attached = $other->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero', 'priority' => 8, 'is_primary' => true]);
    $two = [...$one, 'source_id' => $other->source_id, 'spot_id' => $other->id, 'attachment_id' => $attached->id];
    $records = [$one, $two];
    $service = new MediaRecoveryJournal;
    $id = (string) Str::uuid();
    $context = recoveryPhotoContext($records);
    $service->apply($id, $context, $records, $service->snapshot([$spot->id, $other->id]), 'test-reviewer');
    expect($attached->fresh()->priority)->toBe(8)
        ->and($attached->fresh()->is_primary)->toBeTrue()
        ->and(app(PublishedMediaSelector::class)->select($other->fresh(), 'hero')->id)->toBe($asset->id)
        ->and(DB::table('media_match_reviews')->count())->toBe(2);
    Http::assertSentCount(1);
    $service->recover($id, $context, 'test-reviewer', 'Restore this shared-asset rehearsal cohort.');
    expect($attached->fresh()->match_status)->toBe('pending');
});

test('media recovery refuses locked or previously decided matches', function (string $state) {
    [$record, $spot, , $attachment] = recoveryPhotoFixture();
    $attachment->update($state === 'locked' ? ['is_manually_locked' => true] : ['match_status' => $state]);
    $records = [$record];
    $service = new MediaRecoveryJournal;
    expect(fn () => $service->apply((string) Str::uuid(), recoveryPhotoContext($records), $records, $service->snapshot([$spot->id]), 'test-reviewer'))->toThrow(DomainException::class);
    Http::assertNothingSent();
})->with(['locked', 'rejected', 'accepted']);

test('media recovery rejects tampered receipts and new shared attachment drift', function (string $change) {
    [$record, $spot, $asset] = recoveryPhotoFixture();
    $records = [$record];
    $service = new MediaRecoveryJournal;
    $id = (string) Str::uuid();
    $context = recoveryPhotoContext($records);
    $service->apply($id, $context, $records, $service->snapshot([$spot->id]), 'test-reviewer');
    if ($change === 'receipt') {
        $row = DB::table('place_catalogue_operations')->where('id', $id)->first();
        $receipt = json_decode($row->receipt, true);
        $receipt['records'] = 900;
        DB::table('place_catalogue_operations')->where('id', $id)->update(['receipt' => json_encode($receipt)]);
    } else {
        $other = Spot::factory()->create();
        $other->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero']);
    }
    $before = $service->snapshot([$spot->id]);
    expect(fn () => $service->recover($id, $context, 'test-reviewer', 'Attempt recovery with post-operation drift.'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($before);
})->with(['receipt', 'attachment']);

test('media recovery requires eligible owners and complete matching publication evidence', function (string $change) {
    [$record, $spot] = recoveryPhotoFixture();
    match ($change) {
        'held' => $spot->update(['is_recommendable' => false]),
        'author' => $record['metadata']['author'] = null,
        'page' => $record['metadata']['source_page_url'] = 'https://commons.wikimedia.org/wiki/File:Unrelated.png',
    };
    $records = [$record];
    $service = new MediaRecoveryJournal;
    expect(fn () => $service->apply((string) Str::uuid(), recoveryPhotoContext($records), $records, $service->snapshot([$spot->id]), 'test-reviewer'))->toThrow(DomainException::class);
    Http::assertNothingSent();
})->with(['held', 'author', 'page']);

test('media recovery refuses changes to existing shared owners and their identity family', function (string $change) {
    [$record, $spot, $asset] = recoveryPhotoFixture();
    $other = Spot::factory()->create();
    $related = Spot::factory()->create();
    if ($change === 'alias') {
        $related->forceFill(['canonical_spot_id' => $other->id])->save();
    } elseif ($change === 'parent') {
        $other->update(['parent_spot_id' => $related->id]);
    }
    $other->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero']);
    $service = new MediaRecoveryJournal;
    $records = [$record];
    $context = recoveryPhotoContext($records);
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    $service->apply($id, $context, $records, $before, 'test-reviewer');
    ($change === 'owner' ? $other : $related)->update(['name' => 'Changed after the photo operation']);
    $changed = $service->snapshot([$spot->id]);
    expect(fn () => $service->apply($id, $context, $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect(fn () => (new MediaRecoveryJournal)->recover($id, $context, 'test-reviewer', 'Attempt recovery after a shared owner changed.'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($changed);
    Http::assertSentCount(1);
})->with(['owner', 'alias', 'parent']);

test('media recovery refuses unsupported shared owner types before changes', function () {
    [$record, $spot, $asset] = recoveryPhotoFixture();
    $unsupported = $spot->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'gallery']);
    $unsupported->update(['mediable_type' => 'unsupported-owner']);
    expect(fn () => (new MediaRecoveryJournal)->snapshot([$spot->id]))->toThrow(DomainException::class);
    Http::assertNothingSent();
});

test('media recovery rolls back when later records displace an earlier reviewed hero', function () {
    [$one, $spot, , $first] = recoveryPhotoFixture();
    [$two, , , $second] = recoveryPhotoFixture(2);
    $spot->update(['tags' => [...$spot->tags, 'wikidata' => 'Q1234']]);
    $first->update(['is_primary' => false, 'priority' => 99]);
    $second->update(['mediable_id' => $spot->id, 'is_primary' => false, 'priority' => 1]);
    $two = [...$two, 'spot_id' => $spot->id, 'source_id' => $spot->source_id, 'method' => 'osm_wikidata_p18', 'reference' => 'Q1234'];
    $records = [$one, $two];
    $service = new MediaRecoveryJournal;
    $before = $service->snapshot([$spot->id]);
    $id = (string) Str::uuid();
    expect(fn () => $service->apply($id, recoveryPhotoContext($records), $records, $before, 'test-reviewer'))->toThrow(DomainException::class);
    expect($service->snapshot([$spot->id]))->toBe($before)
        ->and(DB::table('place_catalogue_operations')->where('id', $id)->exists())->toBeFalse();
});
