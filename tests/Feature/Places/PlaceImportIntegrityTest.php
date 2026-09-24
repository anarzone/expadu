<?php

use App\Jobs\ValidateMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use App\Places\ReviewPlaceFacts;
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

test('osm import records source facts once and advances the revision only when facts change', function () {
    seedTestVeedelBoundary();
    $element = [
        'type' => 'way',
        'id' => 12,
        'center' => ['lat' => 50.951, 'lon' => 6.951],
        'tags' => [
            'name' => 'Quellenpark',
            'name:en' => 'Source Park',
            'alt_name' => 'Alter Parkname',
            'short_name' => 'QP',
            'access' => 'yes',
            'access:conditional' => 'no @ (22:00-06:00)',
            'fee' => 'no',
            'opening_hours' => 'Mo-Su 06:00-22:00',
            'contact:website' => 'https://official.example.test/quellenpark',
            'contact:phone' => '+49 221 120',
            'addr:street' => 'Parkweg',
            'addr:housenumber' => '12',
            'addr:city' => 'Köln',
            'description' => 'A short source description.',
            'wheelchair' => 'no',
            'lit' => 'no',
        ],
    ];
    $changed = $element;
    $changed['tags']['name'] = 'Quellenpark am See';
    $changed['tags']['fee'] = 'yes';
    Http::fakeSequence()
        ->push(['elements' => [$element]])
        ->push(['elements' => [$element]])
        ->push(['elements' => [$changed]]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    $spot = Spot::query()->where('source_id', 'way/12')->sole();
    $observation = PlaceFactObservation::query()->sole();
    $revision = app(PlaceFacts::class)->revision();

    expect($observation->provider)->toBe('osm')
        ->and($observation->provider_record_id)->toBe('way/12')
        ->and($observation->source_url)->toBe('https://www.openstreetmap.org/way/12')
        ->and($observation->payload)->toMatchArray([
            'name' => 'Quellenpark',
            'aliases' => ['Alter Parkname', 'QP', 'Source Park'],
            'location' => [
                'lat' => 50.951,
                'lng' => 6.951,
                'kind' => 'source_center',
                'boundary_reference' => 'way/12',
            ],
            'access' => ['raw' => 'yes', 'conditional' => 'no @ (22:00-06:00)'],
            'fee' => ['raw' => 'no'],
            'hours' => ['raw' => 'Mo-Su 06:00-22:00'],
            'contact' => [
                'website' => 'https://official.example.test/quellenpark',
                'phone' => '+49 221 120',
                'address' => 'Parkweg 12, Köln',
            ],
            'description' => 'A short source description.',
            'negative_facts' => ['lit' => 'no', 'wheelchair' => 'no'],
        ]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect(PlaceFactObservation::query()->where('spot_id', $spot->id)->count())->toBe(1)
        ->and(app(PlaceFacts::class)->revision())->toBe($revision);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect(PlaceFactObservation::query()->where('spot_id', $spot->id)->count())->toBe(2)
        ->and(PlaceFactObservation::query()->where('spot_id', $spot->id)->latest('id')->firstOrFail()->payload['name'])->toBe('Quellenpark am See')
        ->and(app(PlaceFacts::class)->revision())->toBe($revision + 1);
});

test('osm import drops malformed contact urls without losing the place observation', function () {
    seedTestVeedelBoundary();
    Http::fake(['*' => Http::response(['elements' => [[
        'type' => 'node',
        'id' => 14,
        'lat' => 50.951,
        'lon' => 6.951,
        'tags' => [
            'name' => 'Park with malformed website',
            'website' => 'javascript:alert(1)',
        ],
    ]]])]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    $spot = Spot::query()->where('source_id', 'node/14')->sole();
    expect(PlaceFactObservation::query()->where('spot_id', $spot->id)->sole()->payload['contact']['website'])
        ->toBeNull();
});

test('osm refresh preserves a reviewed display name and retains the changed source name', function () {
    seedTestVeedelBoundary();
    $spot = Spot::factory()->create([
        'source' => 'osm',
        'source_id' => 'node/13',
        'source_group' => 'park',
        'name' => 'Old source name',
        'category' => 'park',
        'lat' => 50.95,
        'lng' => 6.95,
    ]);
    $review = app(ReviewPlaceFacts::class);
    $preview = $review->preview($spot->id, ['name' => 'Friendly reviewed name']);
    $review->apply(
        $spot->id,
        ['name' => 'Friendly reviewed name'],
        $preview['fingerprint'],
        'Official evidence confirms the public display name.',
        'places-reviewer@example.test',
    );
    Http::fake(['*' => Http::response(['elements' => [[
        'type' => 'node',
        'id' => 13,
        'lat' => 50.952,
        'lon' => 6.952,
        'tags' => ['name' => 'New source name'],
    ]]])]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    $spot->refresh();
    $facts = app(PlaceFacts::class)->resolve($spot);
    expect($spot->name)->toBe('Friendly reviewed name')
        ->and($spot->lat)->toBe(50.952)
        ->and($spot->lng)->toBe(6.952)
        ->and($facts['name']['value'])->toBe('Friendly reviewed name')
        ->and($facts['aliases'])->toContain('New source name');
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
    $commonsAttachment = $spot->mediaAttachments()->where('media_asset_id', $commons->id)->sole();
    $sourceAttachment = $spot->mediaAttachments()->where('media_asset_id', $sourceImage->id)->sole();

    expect($commons->provider_asset_id)->toBe('File:Stadtwald_Koeln.jpg')
        ->and($commons->remote_url)->toBe('https://commons.wikimedia.org/wiki/Special:FilePath/Stadtwald_Koeln.jpg')
        ->and($commons->source_page_url)->toBe('https://commons.wikimedia.org/wiki/File:Stadtwald_Koeln.jpg')
        ->and($commons->rights_status)->toBe('pending')
        ->and($commonsAttachment->match_status)->toBe('accepted')
        ->and($commonsAttachment->match_method)->toBe('osm_wikimedia_commons_tag')
        ->and($commonsAttachment->match_evidence)->toMatchArray([
            'source_id' => 'node/4242',
            'tag' => 'File:Stadtwald_Koeln.jpg',
        ])
        ->and($sourceImage->remote_url)->toBe('https://images.example.org/osm/park.jpg')
        ->and($sourceImage->rights_status)->toBe('pending')
        ->and($sourceAttachment->match_status)->toBe('pending')
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
    app(RecordPlaceObservation::class)->record($existing, [
        'provider' => 'osm',
        'provider_record_id' => 'node/88',
        'source_url' => 'https://www.openstreetmap.org/node/88',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-existing-88',
        'payload' => ['name' => 'Existing place'],
    ]);
    Http::fake(['*' => Http::response(['remark' => 'runtime error'])]);

    $this->artisan('osm:import --only=park')->assertFailed();

    expect($existing->fresh()->is_active)->toBeTrue()
        ->and(PlaceFactObservation::query()->where('spot_id', $existing->id)->count())->toBe(1);
});

test('a valid empty overpass result retires that refreshed source group', function () {
    seedTestVeedelBoundary();
    $existing = Spot::factory()->create([
        'source' => 'osm', 'source_id' => 'node/89', 'source_group' => 'park',
        'last_seen_at' => now()->subDay(), 'is_active' => true, 'is_recommendable' => true,
    ]);
    app(RecordPlaceObservation::class)->record($existing, [
        'provider' => 'osm',
        'provider_record_id' => 'node/89',
        'source_url' => 'https://www.openstreetmap.org/node/89',
        'observed_at' => '2026-09-17T10:00:00+00:00',
        'ingestion_key' => 'osm-existing-89', // gitleaks:allow -- deterministic test fixture, not a credential
        'payload' => ['name' => 'Existing place'],
    ]);
    Http::fake(['*' => Http::response(['elements' => []])]);

    $this->artisan('osm:import --only=park')->assertSuccessful();

    expect($existing->fresh())
        ->is_active->toBeFalse()
        ->is_recommendable->toBeFalse()
        ->and(PlaceFactObservation::query()->where('spot_id', $existing->id)->count())->toBe(1);
});

test('food imports cover citywide nodes ways and relations with stable identities', function (string $category, string $key, string $value) {
    seedTestVeedelBoundary();
    Queue::fake();
    Http::fake(['*' => Http::response(['elements' => [
        ['type' => 'node', 'id' => 771, 'lat' => 50.98, 'lon' => 6.95, 'tags' => ['name' => 'Northern node venue', $key => $value]],
        ['type' => 'way', 'id' => 771, 'center' => ['lat' => 50.981, 'lon' => 6.951], 'tags' => ['name' => 'Northern way venue', $key => $value]],
        ['type' => 'relation', 'id' => 771, 'center' => ['lat' => 50.982, 'lon' => 6.952], 'tags' => ['name' => 'Northern relation venue', $key => $value]],
        ['type' => 'node', 'id' => 772, 'lat' => 51.20, 'lon' => 7.20, 'tags' => ['name' => 'Outside venue', $key => $value]],
    ]])]);

    $this->artisan('osm:import', ['--only' => $category])->assertSuccessful();

    Http::assertSent(function ($request) use ($key, $value) {
        $query = $request['data'];

        return str_contains($query, 'nwr[')
            && str_contains($query, '"'.$key.'"')
            && str_contains($query, $value)
            && str_contains($query, '(50.83,6.77,51.09,7.16)')
            && str_contains($query, 'out center');
    });
    $venues = Spot::query()->where('source', 'osm')->where('category', $category)->get();
    expect($venues)->toHaveCount(3)
        ->and($venues->pluck('source_id')->all())->toContain('node/771', 'way/771', 'relation/771')
        ->and($venues->every(fn (Spot $spot) => $spot->is_active && $spot->is_recommendable))->toBeTrue();
    expect(PlaceFactObservation::query()->where('provider', 'osm')->count())->toBe(3);

    $this->artisan('osm:import', ['--only' => $category])->assertSuccessful();
    expect(Spot::query()->where('source', 'osm')->where('category', $category)->count())->toBe(3);
})->with([
    ['cafe', 'amenity', 'cafe'],
    ['restaurant', 'amenity', 'restaurant'],
    ['fast_food', 'amenity', 'fast_food'],
    ['bar', 'amenity', 'bar'],
    ['bakery', 'shop', 'bakery'],
]);

test('overlapping cafe bakery tags keep their category and refresh ownership across partial imports', function () {
    seedTestVeedelBoundary();
    Queue::fake();
    Http::fake(['*' => Http::response(['elements' => [
        ['type' => 'node', 'id' => 880, 'lat' => 50.98, 'lon' => 6.95, 'tags' => ['name' => 'Bakery cafe', 'amenity' => 'cafe', 'shop' => 'bakery']],
    ]])]);

    foreach (['cafe,bakery', 'cafe', 'bakery'] as $refresh) {
        $this->artisan('osm:import', ['--only' => $refresh])->assertSuccessful();
        $venue = Spot::query()->where('source', 'osm')->where('source_id', 'node/880')->sole();
        expect($venue->getRawOriginal('category'))->toBe('cafe')
            ->and($venue->source_group)->toBe('cafe')
            ->and($venue->is_active)->toBeTrue();
    }
    expect(Spot::query()->where('source', 'osm')->count())->toBe(1);
});
