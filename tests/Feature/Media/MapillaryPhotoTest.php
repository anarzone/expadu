<?php

use App\Media\MapillaryPhotoResolver;
use App\Models\MediaAcquisitionAttempt;
use App\Models\MediaAsset;
use App\Models\Spot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/** A Mapillary frame: camera position, where it looked, and its thumbnails. */
function mapillaryFrame(string $id, float $lat, float $lng, ?float $heading, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'geometry' => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
        'compass_angle' => $heading,
        'thumb_2048_url' => "https://scontent-mxp1-1.xx.fbcdn.net/{$id}.jpg",
        'creator' => ['username' => 'street_mapper'],
        'is_pano' => false,
    ], $overrides);
}

beforeEach(function () {
    config()->set('media.mapillary.token', 'test-token');
});

test('it keeps only the frame whose camera was facing the place', function () {
    // Spot sits north of a road. One camera looks north (at it), one looks
    // south (away, at whatever is across the street) and is closer.
    $spotLat = 50.9500;
    $spotLng = 6.9500;

    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => [
        // 15m south of the spot, pointing north (0°) → looking AT it
        mapillaryFrame('facing', 50.94987, 6.9500, 0.0),
        // 8m south of the spot but pointing south (180°) → looking AWAY
        mapillaryFrame('away', 50.94993, 6.9500, 180.0),
    ]])]);

    $resolved = app(MapillaryPhotoResolver::class)->resolve($spotLat, $spotLng);

    expect($resolved)->not->toBeNull()
        ->and($resolved['provider_asset_id'])->toBe('facing')
        ->and($resolved['license_code'])->toBe('CC BY-SA 4.0')
        ->and($resolved['attribution'])->toBe('street_mapper · CC BY-SA 4.0 · Mapillary');
});

test('it returns nothing when every nearby camera pointed away', function () {
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => [
        mapillaryFrame('away-1', 50.94990, 6.9500, 180.0),
        mapillaryFrame('away-2', 50.95010, 6.9500, 0.0), // north of spot, looking further north
    ]])]);

    expect(app(MapillaryPhotoResolver::class)->resolve(50.9500, 6.9500))->toBeNull();
});

test('it uses the supported bounding-box image search around the target', function () {
    config()->set('media.mapillary.radius_metres', 30);
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => []])]);

    app(MapillaryPhotoResolver::class)->resolve(50.9500, 6.9500);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        $bounds = array_map('floatval', explode(',', (string) ($data['bbox'] ?? '')));

        return count($bounds) === 4
            && ! array_key_exists('lat', $data)
            && ! array_key_exists('lng', $data)
            && ! array_key_exists('radius', $data)
            && $bounds[0] < 6.9500
            && $bounds[1] < 50.9500
            && $bounds[2] > 6.9500
            && $bounds[3] > 50.9500;
    });
});

test('a panorama is accepted without the bearing test', function () {
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => [
        mapillaryFrame('pano', 50.94990, 6.9500, 180.0, ['is_pano' => true]),
    ]])]);

    $resolved = app(MapillaryPhotoResolver::class)->resolve(50.9500, 6.9500);

    expect($resolved['provider_asset_id'] ?? null)->toBe('pano');
});

test('the command captures a rights-approved asset that is not yet healthy', function () {
    $spot = Spot::factory()->create(['category' => 'playground', 'lat' => 50.9500, 'lng' => 6.9500]);

    Http::fake([
        'graph.mapillary.com/*' => Http::response(['data' => [
            mapillaryFrame('frame-1', 50.94987, 6.9500, 0.0),
        ]]),
        '*' => Http::response('', 200),
    ]);

    $this->artisan('photos:fetch-mapillary')->assertSuccessful();

    $asset = MediaAsset::query()->where('provider', 'mapillary')->sole();
    expect($asset->rights_status)->toBe('approved')
        // Bytes are unproven until the validation job runs, so it must not be
        // publishable purely on the strength of a known licence.
        ->and($asset->health_status)->not->toBe('active')
        ->and($spot->fresh()->mediaAttachments()->count())->toBe(1);
});

test('it no-ops without a token instead of calling the API', function () {
    config()->set('media.mapillary.token', null);
    Http::fake();
    Spot::factory()->create(['category' => 'playground', 'lat' => 50.95, 'lng' => 6.95]);

    $this->artisan('photos:fetch-mapillary')->assertSuccessful();

    Http::assertNothingSent();
});

test('command cooldowns advance past earlier misses instead of starving later places', function () {
    $spots = Spot::factory()->count(3)->sequence(
        ['lat' => 50.9500, 'lng' => 6.9500],
        ['lat' => 50.9510, 'lng' => 6.9510],
        ['lat' => 50.9520, 'lng' => 6.9520],
    )->create(['category' => 'playground']);
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => []])]);

    $this->artisan('photos:fetch-mapillary', ['--limit' => 2])->assertSuccessful();
    $this->artisan('photos:fetch-mapillary', ['--limit' => 1])->assertSuccessful();

    expect(MediaAcquisitionAttempt::query()->orderBy('id')->pluck('target_id')->all())
        ->toBe($spots->pluck('id')->all())
        ->and(MediaAcquisitionAttempt::query()->pluck('outcome')->unique()->all())
        ->toBe(['no_result']);
});

test('the command records provider rate limits and honors retry-after', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 08:00:00', 'UTC'));
    Spot::factory()->create(['category' => 'playground', 'lat' => 50.95, 'lng' => 6.95]);
    Http::fake([
        'graph.mapillary.com/*' => Http::response(['error' => 'slow down'], 429, ['Retry-After' => '7200']),
    ]);

    $this->artisan('photos:fetch-mapillary', ['--limit' => 1])->assertSuccessful();

    $attempt = MediaAcquisitionAttempt::query()->sole();
    expect($attempt->outcome)->toBe('rate_limited')
        ->and($attempt->next_attempt_at->equalTo(now()->utc()->addHours(2)))->toBeTrue();
});

test('an active asset with a pending place match does not block another acquisition attempt', function () {
    $spot = Spot::factory()->create(['category' => 'playground', 'lat' => 50.95, 'lng' => 6.95]);
    $asset = MediaAsset::factory()->approved()->create();
    $spot->mediaAttachments()->create([
        'media_asset_id' => $asset->id,
        'role' => 'hero',
        'match_status' => 'pending',
    ]);
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => []])]);

    $this->artisan('photos:fetch-mapillary', ['--limit' => 1])->assertSuccessful();

    Http::assertSentCount(1);
    expect(MediaAcquisitionAttempt::query()->sole()->outcome)->toBe('no_result');
});

test('the command schedules only canonical spots and does not repeat work for aliases', function () {
    $canonical = Spot::factory()->create([
        'category' => 'playground',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $alias = Spot::factory()->create([
        'category' => 'playground',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $alias->forceFill(['canonical_spot_id' => $canonical->id])->save();
    Http::fake(['graph.mapillary.com/*' => Http::response(['data' => []])]);

    $this->artisan('photos:fetch-mapillary', ['--limit' => 2])->assertSuccessful();

    Http::assertSentCount(1);
    expect(MediaAcquisitionAttempt::query()->sole()->target_id)->toBe($canonical->id);
});
