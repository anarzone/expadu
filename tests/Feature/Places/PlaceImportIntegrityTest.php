<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\Spot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function seedTestVeedelBoundary(): void
{
    $index = 0;
    foreach (config('veedels') as $bezirk => $names) {
        foreach ($names as $name) {
            DB::table('veedels')->insert([
                'name' => $name,
                'bezirk' => $bezirk,
                'centroid_lat' => 50.95,
                'centroid_lng' => 6.95,
            ]);
            $wkt = $index === 0
                ? 'POLYGON((6.90 50.90, 7.00 50.90, 7.00 51.00, 6.90 51.00, 6.90 50.90))'
                : 'POLYGON((8.00 52.00, 8.01 52.00, 8.01 52.01, 8.00 52.01, 8.00 52.00))';
            DB::statement('UPDATE veedels SET boundary = ST_Multi(ST_GeomFromText(?, 4326)) WHERE name = ?', [$wkt, $name]);
            $index++;
        }
    }
}

test('veedel import stores official polygon boundaries', function () {
    $features = [];
    foreach (config('veedels') as $bezirk => $names) {
        foreach ($names as $name) {
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'NAME' => match ($name) {
                        'Altstadt-Nord' => 'Altstadt/Nord',
                        'Altstadt-Süd' => 'Altstadt/Süd',
                        'Neustadt-Nord' => 'Neustadt/Nord',
                        'Neustadt-Süd' => 'Neustadt/Süd',
                        default => $name,
                    },
                    'STADTBEZIR' => $bezirk,
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => $name === 'Flittard'
                        ? [[[6.90, 50.94], [6.92, 50.96], [6.92, 50.94], [6.90, 50.96], [6.90, 50.94]]]
                        : [[[6.90, 50.94], [6.92, 50.94], [6.92, 50.96], [6.90, 50.96], [6.90, 50.94]]],
                ],
            ];
        }
    }
    Http::fake([
        '*' => Http::response([
            'type' => 'FeatureCollection',
            'features' => $features,
        ]),
    ]);

    $this->artisan('veedels:import')->assertSuccessful();

    expect(DB::table('veedels')->where('name', 'Ehrenfeld')->whereNotNull('boundary')->exists())->toBeTrue();
});

test('an incomplete boundary download leaves the existing polygon set untouched', function () {
    seedTestVeedelBoundary();
    Http::fake(['*' => Http::response(['type' => 'FeatureCollection', 'features' => []])]);

    $this->artisan('veedels:import')->assertFailed();

    expect(DB::table('veedels')->whereNotNull('boundary')->count())->toBe(86);
});

test('polygon assignment quarantines an outside osm spot instead of using the nearest centroid', function () {
    seedTestVeedelBoundary();
    $outside = Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/123',
        'lat' => 51.20,
        'lng' => 7.20,
        'veedel' => 'Wrong',
        'is_active' => true,
        'is_recommendable' => true,
    ]);

    $this->artisan('spots:assign-veedel --force')->assertSuccessful();

    expect($outside->fresh())
        ->veedel->toBeNull()
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse();
});

test('osm import updates by stable identity and excludes points outside Cologne polygons', function () {
    seedTestVeedelBoundary();
    Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/10',
        'source_group' => 'park',
        'name' => 'Old park name',
        'category' => 'park',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $missingFromRefresh = Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/9',
        'source_group' => 'park',
        'category' => 'park',
        'lat' => 50.952,
        'lng' => 6.952,
        'last_seen_at' => now()->subDay(),
        'is_active' => true,
        'is_recommendable' => true,
    ]);

    Http::fake([
        '*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 10, 'lat' => 50.951, 'lon' => 6.951, 'tags' => ['name' => 'Updated park']],
            ['type' => 'node', 'id' => 11, 'lat' => 51.20, 'lon' => 7.20, 'tags' => ['name' => 'Outside park']],
        ]]),
    ]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect(Spot::query()->where('source_id', 'node/11')->exists())->toBeFalse()
        ->and(Spot::query()->where('source_id', 'node/10')->first())
        ->name->toBe('Updated park')
        ->veedel->not->toBeNull()
        ->is_active->toBeTrue()
        ->last_seen_at->not->toBeNull()
        ->and($missingFromRefresh->fresh())
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse();
});

test('bare microfacilities remain stored but are not recommendation destinations', function () {
    seedTestVeedelBoundary();
    Http::fake([
        '*' => Http::response(['elements' => [
            ['type' => 'node', 'id' => 20, 'lat' => 50.951, 'lon' => 6.951, 'tags' => ['name' => 'Spielplatz', 'leisure' => 'playground']],
        ]]),
    ]);

    $this->artisan('osm:import --only=playground')->assertSuccessful();

    expect(Spot::query()->where('source_id', 'node/20')->first())
        ->is_recommendable->toBeFalse()
        ->is_active->toBeTrue();
});

test('osm import captures exact Commons and source image tags with rights pending', function () {
    seedTestVeedelBoundary();
    Queue::fake();
    Http::fake([
        '*' => Http::response(['elements' => [[
            'type' => 'node',
            'id' => 4242,
            'lat' => 50.951,
            'lon' => 6.951,
            'tags' => [
                'name' => 'Park with source media',
                'wikimedia_commons' => 'File:Stadtwald_Koeln.jpg',
                'image' => 'https://images.example.org/osm/park.jpg',
            ],
        ]]]),
    ]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    $spot = Spot::query()->where('source_id', 'node/4242')->sole();
    $commons = MediaAsset::query()->where('provider', 'wikimedia-commons')->sole();
    $sourceImage = MediaAsset::query()->where('provider', 'osm-image')->sole();

    expect($commons->provider_asset_id)->toBe('File:Stadtwald_Koeln.jpg')
        ->and($commons->remote_url)->toBe('https://commons.wikimedia.org/wiki/Special:FilePath/Stadtwald_Koeln.jpg')
        ->and($commons->source_page_url)->toBe('https://commons.wikimedia.org/wiki/File:Stadtwald_Koeln.jpg')
        ->and($commons->rights_status)->toBe('pending')
        ->and($sourceImage->remote_url)->toBe('https://images.example.org/osm/park.jpg')
        ->and($sourceImage->rights_status)->toBe('pending')
        ->and($spot->mediaAttachments()->count())->toBe(2);

    Queue::assertNotPushed(ValidateMediaAssetJob::class);
});

test('legacy rows are quarantined and authoritative osm rows are rebuilt independently', function () {
    seedTestVeedelBoundary();
    $legacy = Spot::factory()->create([
        'source' => null,
        'source_id' => null,
        'name' => 'Legacy Park',
        'category' => 'park',
        'lat' => 50.951,
        'lng' => 6.951,
    ]);
    Http::fake(['*' => Http::response(['elements' => [[
        'type' => 'way', 'id' => 77, 'center' => ['lat' => 50.951, 'lon' => 6.951], 'tags' => ['name' => 'Legacy Park'],
    ]]])]);

    $this->artisan('spots:quarantine-legacy --force')->assertSuccessful();
    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect(Spot::query()->count())->toBe(2)
        ->and($legacy->fresh()->source)->toBeNull()
        ->and($legacy->fresh()->is_active)->toBeFalse()
        ->and($legacy->fresh()->is_recommendable)->toBeFalse()
        ->and(Spot::query()->where('source_id', 'way/77')->exists())->toBeTrue();
});

test('a nearby manual null-source row is never overwritten during osm rebuild', function () {
    seedTestVeedelBoundary();
    $manual = Spot::factory()->create([
        'source' => null, 'source_id' => null, 'name' => 'Same Name',
        'category' => 'park', 'lat' => 50.951, 'lng' => 6.951,
    ]);
    Http::fake(['*' => Http::response(['elements' => [[
        'type' => 'node', 'id' => 78, 'lat' => 50.9511, 'lon' => 6.9511, 'tags' => ['name' => 'Same Name'],
    ]]])]);

    $this->artisan('spots:quarantine-legacy --force')->assertSuccessful();
    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect($manual->fresh()->source)->toBeNull()
        ->and($manual->fresh()->source_id)->toBeNull()
        ->and($manual->fresh()->is_active)->toBeFalse()
        ->and(Spot::query()->where('source_id', 'node/78')->exists())->toBeTrue();
});

test('unmatched legacy rows cannot remain recommendation eligible after an authoritative refresh', function () {
    seedTestVeedelBoundary();
    $legacy = Spot::factory()->create([
        'source' => null, 'source_id' => null, 'name' => 'Disappeared place',
        'category' => 'park', 'lat' => 51.20, 'lng' => 7.20,
        'is_active' => true, 'is_recommendable' => true,
    ]);
    Http::fake(['*' => Http::response(['elements' => []])]);

    $this->artisan('spots:quarantine-legacy --force')->assertSuccessful();
    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect($legacy->fresh())
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse();
});

test('a partial category refresh preserves unrelated legacy rows', function () {
    seedTestVeedelBoundary();
    $legacyCafe = Spot::factory()->create([
        'source' => null, 'source_id' => null, 'name' => 'Legacy Café',
        'category' => 'cafe', 'lat' => 50.951, 'lng' => 6.951,
        'is_active' => true, 'is_recommendable' => true,
    ]);
    Http::fake(['*' => Http::response(['elements' => []])]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect($legacyCafe->fresh())
        ->is_active->toBeTrue()
        ->is_recommendable->toBeTrue();
});

test('the rollout restores legacy places when no authoritative catalogue exists', function () {
    $legacy = Spot::factory()->create([
        'source' => null,
        'is_active' => false,
        'is_recommendable' => false,
    ]);
    $migration = require database_path('migrations/2026_07_12_212246_restore_legacy_spots_when_no_authoritative_catalogue_exists.php');

    $migration->up();

    expect($legacy->fresh())
        ->is_active->toBeTrue()
        ->is_recommendable->toBeTrue();
});

test('the rollout does not reactivate legacy places after an authoritative catalogue exists', function () {
    $legacy = Spot::factory()->create([
        'source' => null,
        'is_active' => false,
        'is_recommendable' => false,
    ]);
    Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/authoritative',
        'is_active' => true,
        'is_recommendable' => true,
    ]);
    $migration = require database_path('migrations/2026_07_12_212246_restore_legacy_spots_when_no_authoritative_catalogue_exists.php');

    $migration->up();

    expect($legacy->fresh())
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse();
});

test('supplied generic dog park and skatepark labels are not destinations', function (string $only, string $name, array $tags) {
    seedTestVeedelBoundary();
    Http::fake(['*' => Http::response(['elements' => [[
        'type' => 'node', 'id' => 90, 'lat' => 50.951, 'lon' => 6.951, 'tags' => ['name' => $name, ...$tags],
    ]]])]);

    $this->artisan("osm:import --only={$only}")->assertSuccessful();

    expect(Spot::query()->where('source_id', 'node/90')->first()->is_recommendable)->toBeFalse();
})->with([
    'dog park' => ['dog_park', 'Hundewiese', ['leisure' => 'dog_park']],
    'skatepark' => ['pitch', 'Skatepark', ['sport' => 'skateboard']],
]);

test('an invalid overpass payload never retires existing places', function () {
    seedTestVeedelBoundary();
    $existing = Spot::factory()->create([
        'source' => 'osm', 'source_id' => 'node/88', 'source_group' => 'park',
        'last_seen_at' => now()->subDay(), 'is_active' => true, 'is_recommendable' => true,
    ]);
    Http::fake(['*' => Http::response(['remark' => 'runtime error'])]);

    $this->artisan('osm:import --only=park')->assertFailed();

    expect($existing->fresh()->is_active)->toBeTrue();
});

test('a valid empty overpass result retires that refreshed source group', function () {
    seedTestVeedelBoundary();
    $existing = Spot::factory()->create([
        'source' => 'osm', 'source_id' => 'node/89', 'source_group' => 'park',
        'last_seen_at' => now()->subDay(), 'is_active' => true, 'is_recommendable' => true,
    ]);
    Http::fake(['*' => Http::response(['elements' => []])]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect($existing->fresh())
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse();
});
