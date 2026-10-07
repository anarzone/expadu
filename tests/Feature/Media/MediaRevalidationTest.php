<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
use App\Models\MediaValidationAttempt;
use App\Models\Spot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

function revalidationPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);

    return $contents;
}

test('media validation scheduling fields and immutable attempt history exist', function () {
    expect(Schema::hasColumns('media_assets', [
        'next_validation_at',
        'validation_queued_at',
        'validation_queued_fingerprint',
        'last_validation_outcome',
        'last_validation_error_code',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('media_validation_attempts', [
            'id',
            'media_asset_id',
            'input_fingerprint',
            'remote_url',
            'checksum',
            'asset_updated_at',
            'outcome',
            'error_code',
            'started_at',
            'finished_at',
            'metadata',
        ]))->toBeTrue()
        ->and(class_exists(MediaValidationAttempt::class))->toBeTrue();
});

test('validation evidence remains after its media asset is removed', function () {
    $asset = MediaAsset::factory()->create();
    $attempt = MediaValidationAttempt::factory()->for($asset)->create();

    $asset->delete();

    expect($attempt->fresh())->not->toBeNull()
        ->and($attempt->fresh()->media_asset_id)->toBeNull();
});

test('successful validation schedules the next weekly check without changing rights', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/healthy.png',
        'rights_status' => 'approved',
    ]);
    $expectedFingerprint = MediaAssetValidator::inputFingerprint($asset);
    $image = revalidationPng(1200, 800);
    Http::fake(['www.stadt-koeln.de/*' => Http::response($image, 200, ['Content-Type' => 'image/png'])]);

    app(MediaAssetValidator::class)->validate($asset);

    $asset->refresh();
    $attempt = MediaValidationAttempt::query()->sole();
    expect($asset->health_status)->toBe('active')
        ->and($asset->rights_status)->toBe('approved')
        ->and($asset->failure_count)->toBe(0)
        ->and($asset->last_validation_outcome)->toBe('active')
        ->and($asset->next_validation_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($attempt->outcome)->toBe('active')
        ->and($attempt->input_fingerprint)->toBe($expectedFingerprint);
});

test('validation intervals are configurable and a healthy retry recovers a broken asset', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    config()->set('media.validation.active_interval_seconds', 7200);
    config()->set('media.validation.failure_retry_seconds', [60]);
    config()->set('media.validation.broken_after_failures', 1);
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/recovery.png',
        'rights_status' => 'approved',
    ]);
    Http::fakeSequence('www.stadt-koeln.de/*')
        ->push('', 503)
        ->push(revalidationPng(1200, 800), 200, ['Content-Type' => 'image/png']);

    app(MediaAssetValidator::class)->validate($asset);
    expect($asset->fresh()->health_status)->toBe('broken')
        ->and($asset->fresh()->next_validation_at->equalTo(now()->addSeconds(60)))->toBeTrue();

    $this->travelTo($asset->fresh()->next_validation_at);
    app(MediaAssetValidator::class)->validate($asset->fresh());

    expect($asset->fresh()->health_status)->toBe('active')
        ->and($asset->fresh()->failure_count)->toBe(0)
        ->and($asset->fresh()->next_validation_at->equalTo(now()->addSeconds(7200)))->toBeTrue()
        ->and(MediaValidationAttempt::query()->pluck('outcome')->all())->toBe(['failed', 'active']);
});

test('a stale validation response cannot overwrite a changed asset URL', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/old.png',
        'health_status' => 'pending',
    ]);
    $expectedFingerprint = MediaAssetValidator::inputFingerprint($asset);
    $image = revalidationPng(1200, 800);
    Http::fake(function () use ($asset, $image) {
        MediaAsset::query()->whereKey($asset->id)->update([
            'remote_url' => 'https://www.stadt-koeln.de/mediaasset/new.png',
            'updated_at' => now(),
        ]);

        return Http::response($image, 200, ['Content-Type' => 'image/png']);
    });

    app(MediaAssetValidator::class)->validate($asset, $expectedFingerprint);

    $asset->refresh();
    expect($asset->remote_url)->toEndWith('/new.png')
        ->and($asset->health_status)->toBe('pending')
        ->and($asset->checksum)->toBeNull()
        ->and(MediaValidationAttempt::query()->sole()->outcome)->toBe('stale_input');
});

test('a queued stale job retains the exact URL and hash it was asked to validate', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/queued-old.png',
        'checksum' => hash('sha256', 'old bytes'),
    ]);
    $job = new ValidateMediaAssetJob($asset);
    $asset->update([
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/queued-new.png',
        'checksum' => null,
    ]);
    Http::preventStrayRequests();

    $job->handle(app(MediaAssetValidator::class));

    $attempt = MediaValidationAttempt::query()->sole();
    expect($attempt->outcome)->toBe('stale_input')
        ->and($attempt->remote_url)->toEndWith('/queued-old.png')
        ->and($attempt->checksum)->toBe(hash('sha256', 'old bytes'))
        ->and($asset->fresh()->remote_url)->toEndWith('/queued-new.png');
    Http::assertNothingSent();
});

test('failed validation backs off one hour six hours twenty four hours then weekly', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/tracker.png',
    ]);
    $tracker = revalidationPng(1, 1);
    Http::fake(['www.stadt-koeln.de/*' => Http::response($tracker, 200, ['Content-Type' => 'image/png'])]);

    foreach ([1, 6, 24, 168] as $hours) {
        app(MediaAssetValidator::class)->validate($asset->fresh());
        $asset->refresh();
        expect($asset->next_validation_at->equalTo(now()->addHours($hours)))->toBeTrue();
        $this->travelTo($asset->next_validation_at);
    }

    expect($asset->health_status)->toBe('broken')
        ->and($asset->rights_status)->toBe('pending')
        ->and($asset->last_verified_at)->toBeNull()
        ->and(MediaValidationAttempt::query()->count())->toBe(4);
});

test('the revalidation command queues only due attached assets with an input guard', function () {
    Queue::fake();
    $spot = Spot::factory()->create();
    $due = MediaAsset::factory()->create(['next_validation_at' => now()->utc()->subMinute()]);
    $future = MediaAsset::factory()->create(['next_validation_at' => now()->utc()->addDay()]);
    $orphan = MediaAsset::factory()->create(['next_validation_at' => null]);
    foreach ([$due, $future] as $asset) {
        $spot->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero']);
    }

    expect(MediaAsset::query()->whereHas('attachments')->pluck('id')->all())
        ->toContain($due->id, $future->id);
    expect($due->fresh()->next_validation_at->lte(now()->utc()))->toBeTrue()
        ->and(MediaAsset::query()
            ->whereHas('attachments')
            ->where(fn ($query) => $query->whereNull('next_validation_at')->orWhere('next_validation_at', '<=', now()->utc()))
            ->pluck('id')->all())->toContain($due->id);

    expect(Artisan::call('media:revalidate', ['--limit' => 200]))->toBe(0);
    $summary = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($summary['queued'])->toBe(1);

    Queue::assertPushed(ValidateMediaAssetJob::class, 1);
    Queue::assertPushed(ValidateMediaAssetJob::class, fn (ValidateMediaAssetJob $job): bool => $job->asset->is($due)
        && $job->expectedInputFingerprint === MediaAssetValidator::inputFingerprint($due->fresh()));
    expect($due->fresh()->validation_queued_at)->not->toBeNull()
        ->and($future->fresh()->validation_queued_at)->toBeNull()
        ->and($orphan->fresh()->validation_queued_at)->toBeNull();
});

test('revalidation queues oldest due assets first and does not duplicate an active lease', function () {
    Queue::fake();
    $spot = Spot::factory()->create();
    $oldest = MediaAsset::factory()->create(['next_validation_at' => now()->utc()->subDays(2)]);
    $newer = MediaAsset::factory()->create(['next_validation_at' => now()->utc()->subDay()]);
    foreach ([$oldest, $newer] as $asset) {
        $spot->mediaAttachments()->create(['media_asset_id' => $asset->id, 'role' => 'hero']);
    }

    $this->artisan('media:revalidate', ['--limit' => 1])->assertSuccessful();
    $this->artisan('media:revalidate', ['--limit' => 1])->assertSuccessful();

    Queue::assertPushed(ValidateMediaAssetJob::class, 2);
    expect($oldest->fresh()->validation_queued_at)->not->toBeNull()
        ->and($newer->fresh()->validation_queued_at)->not->toBeNull();
});

test('media revalidation runs hourly with overlap and single-server guards', function () {
    Artisan::call('schedule:list', ['--json' => true]);
    $events = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    $event = collect($events)->firstWhere('command', 'php artisan media:revalidate --limit=200');

    expect($event)->not->toBeNull()
        ->and($event['expression'])->toBe('0 * * * *');
});
