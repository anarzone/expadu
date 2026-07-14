<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Media\MediaAssetValidator;
use App\Models\MediaAsset;
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

    expect($job->uniqueId())->toBe((string) $asset->id)
        ->and($asset->fresh()->health_status)->toBe('active');

    $failedAsset = MediaAsset::factory()->create();
    (new ValidateMediaAssetJob($failedAsset))->failed(new RuntimeException('remote unavailable'));

    expect($failedAsset->fresh()->failure_count)->toBe(1)
        ->and($failedAsset->fresh()->last_error)->toContain('remote unavailable');
});
