<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
use App\Models\MediaValidationAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function mediaTestPng(int $width, int $height): string
{
    $image = imagecreatetruecolor($width, $height);
    ob_start();
    imagepng($image);
    $contents = (string) ob_get_clean();
    imagedestroy($image);

    return $contents;
}

test('media validator and provider safety policy are available', function () {
    expect(class_exists('App\\Media\\MediaAssetValidator'))->toBeTrue()
        ->and(config('media.providers.stadt-koeln.hosts'))->toBe(['www.stadt-koeln.de'])
        ->and(config('media.providers.koeln-de.hosts'))->toBe(['www.koeln.de'])
        ->and(config('media.providers.wikimedia-commons.hosts'))->toContain(
            'commons.wikimedia.org',
            'upload.wikimedia.org',
        );
});

test('media validator exposes validation and failure recording operations', function () {
    expect(method_exists(MediaAssetValidator::class, 'validate'))->toBeTrue()
        ->and(method_exists(MediaAssetValidator::class, 'recordFailure'))->toBeTrue();
});

test('mapillary validation accepts only explicitly audited CDN hosts', function () {
    expect(MediaAssetValidator::isAllowedProviderUrl(
        'mapillary',
        'https://scontent-mxp1-1.xx.fbcdn.net/photo.jpg',
    ))->toBeTrue()
        ->and(MediaAssetValidator::isAllowedProviderUrl(
            'mapillary',
            'https://scontent-fra5-2.xx.fbcdn.net/photo.jpg',
        ))->toBeFalse()
        ->and(MediaAssetValidator::isAllowedProviderUrl(
            'mapillary',
            'https://evilfbcdn.net/photo.jpg',
        ))->toBeFalse()
        ->and(MediaAssetValidator::isAllowedProviderUrl(
            'mapillary',
            'https://fbcdn.net.attacker.example/photo.jpg',
        ))->toBeFalse();
});

test('a healthy provider image is verified without granting unknown publishing rights', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/poster.png',
    ]);
    $image = mediaTestPng(1200, 800);

    Http::preventStrayRequests();
    Http::fake([
        'www.stadt-koeln.de/*' => Http::response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($image),
        ]),
    ]);

    app(MediaAssetValidator::class)->validate($asset);

    $asset->refresh();
    expect($asset->health_status)->toBe('active')
        ->and($asset->rights_status)->toBe('pending')
        ->and($asset->mime_type)->toBe('image/png')
        ->and($asset->width)->toBe(1200)
        ->and($asset->height)->toBe(800)
        ->and($asset->failure_count)->toBe(0)
        ->and($asset->last_verified_at)->not->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->hasHeader('Range', 'bytes=0-10485759'));
});

test('repeated invalid image responses mark an asset broken without approving it', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/tracker.png',
    ]);
    $tracker = mediaTestPng(1, 1);

    Http::fake([
        'www.stadt-koeln.de/*' => Http::response($tracker, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($tracker),
        ]),
    ]);

    app(MediaAssetValidator::class)->validate($asset);
    app(MediaAssetValidator::class)->validate($asset->fresh());
    app(MediaAssetValidator::class)->validate($asset->fresh());

    $asset->refresh();
    expect($asset->health_status)->toBe('broken')
        ->and($asset->rights_status)->toBe('pending')
        ->and($asset->failure_count)->toBe(3)
        ->and($asset->last_error)->toBe('invalid_or_too_small_image');
});

test('a partial response is rejected when content range reveals an oversized original', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/oversized.png',
    ]);
    $partialImage = mediaTestPng(1200, 800);

    Http::fake([
        'www.stadt-koeln.de/*' => Http::response($partialImage, 206, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($partialImage),
            'Content-Range' => 'bytes 0-'.(strlen($partialImage) - 1).'/20000000',
        ]),
    ]);

    app(MediaAssetValidator::class)->validate($asset);

    expect($asset->fresh()->health_status)->toBe('pending')
        ->and($asset->fresh()->last_error)->toBe('image_too_large');
});

test('an unapproved provider is rejected before any network request', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'unknown-provider',
        'remote_url' => 'https://images.example.test/photo.jpg',
    ]);
    Http::preventStrayRequests();

    app(MediaAssetValidator::class)->validate($asset);

    expect($asset->fresh()->last_error)->toBe('provider_or_url_not_allowed')
        ->and($asset->fresh()->failure_count)->toBe(1);
    Http::assertNothingSent();
});

test('the unique validation job delegates safely and records terminal queue failures', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'koeln-de',
        'remote_url' => 'https://www.koeln.de/images/event.png',
    ]);
    $image = mediaTestPng(1000, 700);
    Http::fake([
        'www.koeln.de/*' => Http::response($image, 200, [
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($image),
        ]),
    ]);

    $job = new ValidateMediaAssetJob($asset);
    $job->handle(app(MediaAssetValidator::class));

    expect($job->uniqueId())->toStartWith($asset->id.':')
        ->and($asset->fresh()->health_status)->toBe('active');

    $failedAsset = MediaAsset::factory()->create([
        'health_status' => 'active',
        'failure_count' => 0,
        'next_validation_at' => now()->addWeek(),
    ]);
    (new ValidateMediaAssetJob($failedAsset))->failed(new RuntimeException('queue worker stopped'));

    expect($failedAsset->fresh()->failure_count)->toBe(0)
        ->and($failedAsset->fresh()->health_status)->toBe('active')
        ->and($failedAsset->fresh()->last_validation_outcome)->toBe('job_failed')
        ->and($failedAsset->fresh()->next_validation_at->lte(now()))->toBeTrue()
        ->and(MediaValidationAttempt::query()->where('media_asset_id', $failedAsset->id)->sole()->outcome)
        ->toBe('job_failed');
});

test('the validation job keeps one retry deadline after time advances', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    $job = new ValidateMediaAssetJob(MediaAsset::factory()->create());
    $deadline = $job->retryUntil()->getTimestamp();

    $this->travel(12)->hours();

    expect($job->retryUntil()->getTimestamp())->toBe($deadline);
});

test('a successful non-image response is rejected as content evidence', function () {
    $asset = MediaAsset::factory()->create([
        'provider' => 'stadt-koeln',
        'remote_url' => 'https://www.stadt-koeln.de/mediaasset/not-an-image.jpg',
    ]);
    Http::fake([
        'www.stadt-koeln.de/*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html']),
    ]);

    app(MediaAssetValidator::class)->validate($asset);

    expect($asset->fresh()->health_status)->toBe('pending')
        ->and($asset->fresh()->last_validation_error_code)->toBe('unsupported_mime_type');
});
