<?php

use App\Models\MediaAsset;
use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserLocationService;
use App\Transit\Contracts\RouteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // No stray HTTP: by default the one-to-many matrix returns nothing, so
    // distance falls back to the haversine heuristic (as it did before).
    Http::fake();
    $this->user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld']);
    UserPlace::factory()->create([
        'user_id' => $this->user->id,
        'category' => 'home',
        'lat' => 50.948,
        'lng' => 6.921,
    ]);
    $this->actingAs($this->user);
});

test('lists leisure places with the full contract shape', function () {
    Spot::factory()->create([
        'name' => 'Grüngürtel court',
        'category' => 'basketball',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
    ]);

    $response = $this->getJson('/api/places');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [['id', 'name', 'category', 'veedel', 'lat', 'lng', 'photo_url', 'distance_min', 'distance_mode', 'distance_km', 'open_now', 'opening_hours_text', 'price_text', 'feature_chips', 'tip', 'transit_hint', 'facts']],
        'meta' => ['total'],
    ]);
    // basketball rolls up to the coarse 'court' bucket but keeps its fine identity
    $response->assertJsonPath('data.0.category', 'court');
    $response->assertJsonPath('data.0.fine_label', 'Basketball court');
    $response->assertJsonPath('data.0.emoji', '🏀');
    $response->assertJsonPath('data.0.open_now', true);
    $response->assertJsonPath('data.0.price_text', 'free');
    // no per-place tip stored → the category fallback is marked generic
    $response->assertJsonPath('data.0.tip_is_generic', true);
});

test('place cards expose only approved active media and prefer it over legacy columns', function () {
    $spot = Spot::factory()->create([
        'category' => 'park',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
        'photo_url' => 'https://legacy.example/old.jpg',
        'photo_attribution' => 'Legacy credit',
    ]);
    $pending = MediaAsset::factory()->create(['remote_url' => 'https://source.example/pending.jpg']);
    $approved = MediaAsset::factory()->approved()->create([
        'remote_url' => 'https://upload.wikimedia.org/place.jpg',
        'attribution' => 'Approved Commons credit',
        'source_page_url' => 'https://commons.wikimedia.org/wiki/File:Place.jpg',
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => $pending->id,
        'role' => 'hero',
        'priority' => 1,
        'is_primary' => true,
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => $approved->id,
        'role' => 'hero',
        'priority' => 2,
    ]);

    $place = $this->getJson('/api/places')->assertOk()->json('data.0');

    expect($place['photo_url'])->toBe('https://upload.wikimedia.org/place.jpg')
        ->and($place['photo_attribution'])->toBe('Approved Commons credit')
        ->and($place['photo_source_url'])->toBe('https://commons.wikimedia.org/wiki/File:Place.jpg')
        ->and($place['photo_license_url'])->toBe('https://creativecommons.org/licenses/by/4.0/');
});

test('managed pending media cannot fall through to legacy photo columns', function () {
    $spot = Spot::factory()->create([
        'category' => 'park',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
        'photo_url' => 'https://legacy.example/unsafe-bypass.jpg',
        'photo_attribution' => 'Legacy credit',
    ]);
    $spot->mediaAttachments()->create([
        'media_asset_id' => MediaAsset::factory()->create()->id,
        'role' => 'hero',
        'is_primary' => true,
    ]);

    $place = $this->getJson('/api/places')->assertOk()->json('data.0');

    expect($place['photo_url'])->toBeNull()
        ->and($place['photo_attribution'])->toBeNull();
});

test('an unavailable route shows distance instead of invented travel minutes', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.968, 'lng' => 6.921]);
    $origin = 'lat=50.941&lng=6.921';

    $this->user->update(['transport_mode' => 'walk']);
    $place = $this->getJson("/api/places?veedel=Ehrenfeld&{$origin}")->json('data.0');

    expect($place['distance_min'])->toBeNull()
        ->and($place['distance_km'])->toBeGreaterThan(2.0)
        ->and($place['distance_mode'])->toBe('walk');
});

test('an explicit From by saved-place id measures from that place', function () {
    $work = UserPlace::factory()->create([
        'user_id' => $this->user->id,
        'category' => 'work',
        'name' => 'Work',
        'lat' => 50.968,
        'lng' => 6.921,
    ]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.921]);

    $res = $this->getJson("/api/places?veedel=Ehrenfeld&from_place={$work->id}");

    $res->assertOk()
        ->assertJsonPath('origin.source', 'live')
        ->assertJsonPath('origin.label', 'Work');
    // The origin echoes the saved place's coordinates so take-me-there agrees.
    expect((float) $res->json('origin.lat'))->toBe(50.968);
    expect((float) $res->json('origin.lng'))->toBe(6.921);
});

test('a From id belonging to another user is ignored', function () {
    $other = User::factory()->onboarded()->create();
    $theirPlace = UserPlace::factory()->create([
        'user_id' => $other->id,
        'category' => 'home',
        'lat' => 50.80,
        'lng' => 7.00,
    ]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.921]);

    $res = $this->getJson("/api/places?veedel=Ehrenfeld&from_place={$theirPlace->id}");

    // Scoped to the user's own places → the foreign id can't anchor distances.
    $res->assertOk();
    expect($res->json('origin.source'))->not->toBe('live');
});

test('an explicit From by geocoded point carries its label', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.921]);

    $res = $this->getJson('/api/places?veedel=Ehrenfeld&lat=50.95&lng=6.92&from_label=Neumarkt');

    $res->assertOk()
        ->assertJsonPath('origin.source', 'live')
        ->assertJsonPath('origin.label', 'Neumarkt');
});

test('excludes indoor/legacy categories from Places', function () {
    Spot::factory()->create(['category' => 'cafe', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);

    $response = $this->getJson('/api/places');

    expect(collect($response->json('data'))->pluck('category')->all())->not->toContain('other');
    expect(collect($response->json('data'))->pluck('category')->all())->toContain('park');
});

test('filters by coarse category', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);

    $response = $this->getJson('/api/places?category=court');

    $cats = collect($response->json('data'))->pluck('category')->unique()->all();
    expect($cats)->toBe(['court']);
});

test('filters by veedel and lifts the filter with all', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Nippes', 'lat' => 50.96, 'lng' => 6.95]);

    // No veedels centroid seeded → strict filter, no "& nearby" claim.
    $response = $this->getJson('/api/places?veedel=Ehrenfeld');
    expect($response->json('meta.total'))->toBe(1);
    expect($response->json('nearby_included'))->toBeFalse();
    expect($this->getJson('/api/places?veedel=all')->json('meta.total'))->toBe(2);
});

test('includes places within 2km of the veedel centroid as nearby', function () {
    DB::table('veedels')->insert([
        'name' => 'Ehrenfeld',
        'bezirk' => 'Ehrenfeld',
        'centroid_lat' => 50.949,
        'centroid_lng' => 6.917,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Three places within ~2km of the centroid → the ring is dense enough that
    // it needn't widen: the Veedel's own plus two in a neighbouring Veedel.
    $inVeedel = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.941, 'lng' => 6.905]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Neuehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Neuehrenfeld', 'lat' => 50.955, 'lng' => 6.917]);
    // Other side of the city, well outside the ring → excluded (the ring
    // already holds 3, so it doesn't widen to reach it).
    $far = Spot::factory()->create(['category' => 'park', 'veedel' => 'Porz', 'lat' => 50.886, 'lng' => 7.058]);

    $response = $this->getJson('/api/places?veedel=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(3);
    expect($response->json('nearby_included'))->toBeTrue();
    expect(collect($response->json('data'))->pluck('id'))->not->toContain($far->id);
    // The selected Veedel's own place still ranks above the nearby ones.
    expect($response->json('data.0.id'))->toBe($inVeedel->id);
});

test('a sparse veedel widens the ring to reach at least 3 results', function () {
    DB::table('veedels')->insert([
        'name' => 'Sparseveedel',
        'bezirk' => 'Test',
        'centroid_lat' => 50.95,
        'centroid_lng' => 6.95,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Nothing in the Veedel or its default 2km ring; the three nearest parks sit
    // ~2.5km out. The list must still surface all three (the ring widens).
    foreach ([[50.9725, 6.95], [50.9728, 6.951], [50.973, 6.949]] as [$lat, $lng]) {
        Spot::factory()->create(['category' => 'park', 'veedel' => 'Elsewhere', 'lat' => $lat, 'lng' => $lng]);
    }

    $response = $this->getJson('/api/places?veedel=Sparseveedel');

    // The old fixed 2km ring would have returned 0; the widening reaches 3.
    expect($response->json('meta.total'))->toBe(3);
});

test('filters by bezirk across its stadtteile', function () {
    foreach (['Ehrenfeld' => 'Ehrenfeld', 'Neuehrenfeld' => 'Ehrenfeld', 'Nippes' => 'Nippes'] as $name => $bezirk) {
        DB::table('veedels')->insert([
            'name' => $name,
            'bezirk' => $bezirk,
            'centroid_lat' => 50.94,
            'centroid_lng' => 6.92,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Spot::factory()->create(['category' => 'park', 'veedel' => $name, 'lat' => 50.94, 'lng' => 6.92]);
    }

    $response = $this->getJson('/api/places?bezirk=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('nearby_included'))->toBeFalse();
    expect(collect($response->json('data'))->pluck('veedel')->sort()->values()->all())
        ->toBe(['Ehrenfeld', 'Neuehrenfeld']);
});

test('collapses identically-named places within ~100m into one card', function () {
    // Three same-name tables in one park corner → one card, cluster_size 3
    foreach (range(1, 3) as $i) {
        Spot::factory()->create(['name' => 'Tischtennisplatte', 'category' => 'table_tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.9481, 'lng' => 6.9211]);
    }
    // Same name but a different corner of the Veedel → stays its own card
    Spot::factory()->create(['name' => 'Tischtennisplatte', 'category' => 'table_tennis', 'veedel' => 'Ehrenfeld', 'lat' => 50.9580, 'lng' => 6.9100]);

    $response = $this->getJson('/api/places?veedel=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect(collect($response->json('data'))->pluck('cluster_size')->sort()->values()->all())->toBe([1, 3]);
});

test('OSM tags surface as facts, chips and real opening hours', function () {
    Spot::factory()->create([
        'name' => 'Grüngürtel court',
        'category' => 'basketball',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
        'tags' => ['hoops' => '2', 'surface' => 'asphalt', 'lit' => 'yes', 'opening_hours' => 'Mo-Su 08:00-22:00'],
    ]);

    $place = $this->getJson('/api/places')->json('data.0');

    expect($place['feature_chips'])->toContain('floodlit');
    expect($place['opening_hours_text'])->toBe('Mo-Su 08:00-22:00');
    expect(collect($place['facts'])->pluck('label')->all())->toBe(['hoops', 'surface', 'floodlit']);
    expect(collect($place['facts'])->firstWhere('label', 'hoops')['value'])->toBe('2');
    expect(collect($place['facts'])->firstWhere('label', 'floodlit')['value'])->toBe('Yes');
});

test('facilities inside a park collapse into the park card with activity chips', function () {
    Spot::factory()->create(['name' => 'Blücherpark', 'category' => 'park', 'veedel' => 'Neuehrenfeld', 'lat' => 50.962, 'lng' => 6.930]);
    Spot::factory()->create(['name' => 'Bolzplatz', 'category' => 'pitch', 'park_name' => 'Blücherpark', 'veedel' => 'Neuehrenfeld', 'lat' => 50.9622, 'lng' => 6.9301]);
    Spot::factory()->create(['name' => 'Tennisplatz', 'category' => 'tennis', 'park_name' => 'Blücherpark', 'veedel' => 'Neuehrenfeld', 'lat' => 50.9623, 'lng' => 6.9302]);
    // Standalone facility outside any park stays its own card
    Spot::factory()->create(['name' => 'Bolzplatz', 'category' => 'pitch', 'park_name' => null, 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);

    $data = collect($this->getJson('/api/places')->json('data'));

    // The in-park pitch and tennis court are NOT separate cards
    expect($data->pluck('name')->all())->toContain('Blücherpark');
    expect($data->where('name', 'Bolzplatz')->count())->toBe(1); // only the standalone one
    expect($data->pluck('name')->all())->not->toContain('Tennisplatz');

    // The park card says what you can do inside
    $park = $data->firstWhere('name', 'Blücherpark');
    expect(collect($park['activities'])->pluck('label')->all())->toBe(['Pitch', 'Tennis court']);
});

test('an activity filter returns parks containing it plus standalone facilities', function () {
    Spot::factory()->create(['name' => 'Blücherpark', 'category' => 'park', 'lat' => 50.962, 'lng' => 6.930]);
    Spot::factory()->create(['name' => 'Tennisplatz', 'category' => 'tennis', 'park_name' => 'Blücherpark', 'lat' => 50.9622, 'lng' => 6.9301]);
    Spot::factory()->create(['name' => 'Stadtgarten', 'category' => 'park', 'lat' => 50.945, 'lng' => 6.935]); // park without tennis
    $standalone = Spot::factory()->create(['name' => 'Tennisclub Süd', 'category' => 'tennis', 'park_name' => null, 'lat' => 50.92, 'lng' => 6.94]);

    $names = collect($this->getJson('/api/places?category=court')->json('data'))->pluck('name')->all();

    expect($names)->toContain('Blücherpark');
    expect($names)->toContain('Tennisclub Süd');
    expect($names)->not->toContain('Stadtgarten');
    expect($names)->not->toContain('Tennisplatz'); // collapsed into its park
    expect($standalone)->not->toBeNull();
});

test('facilities inside a mapped sports centre collapse into one destination card', function () {
    $complex = Spot::factory()->create([
        'name' => 'Sportanlage Nippes',
        'category' => 'sports_centre',
        'veedel' => 'Nippes',
        'lat' => 50.964,
        'lng' => 6.954,
    ]);
    Spot::factory()->create([
        'name' => 'Tennisplatz',
        'category' => 'tennis',
        'parent_spot_id' => $complex->id,
        'veedel' => 'Nippes',
        'lat' => 50.9642,
        'lng' => 6.9541,
    ]);
    Spot::factory()->create([
        'name' => 'Bolzplatz',
        'category' => 'pitch',
        'parent_spot_id' => $complex->id,
        'veedel' => 'Nippes',
        'lat' => 50.9643,
        'lng' => 6.9542,
    ]);
    Spot::factory()->create([
        'name' => 'Tennisclub Süd',
        'category' => 'tennis',
        'veedel' => 'Rodenkirchen',
        'lat' => 50.889,
        'lng' => 6.996,
    ]);

    $all = collect($this->getJson('/api/places')->json('data'));
    $complexCard = $all->firstWhere('name', 'Sportanlage Nippes');

    expect($complexCard)->not->toBeNull();
    expect($complexCard['open_now'])->toBeNull()
        ->and($complexCard['price_text'])->toBeNull();
    expect($all->pluck('name')->all())->not->toContain('Tennisplatz', 'Bolzplatz');
    expect(collect($complexCard['activities'])->pluck('label')->all())
        ->toBe(['Pitch', 'Tennis court']);

    $pitchResults = collect($this->getJson('/api/places?category=pitch')->json('data'));
    expect($pitchResults->pluck('name')->all())->toContain('Sportanlage Nippes');
    expect($pitchResults->pluck('name')->all())->not->toContain('Bolzplatz');

    $courtResults = collect($this->getJson('/api/places?category=court')->json('data'));
    expect($courtResults->pluck('name')->all())->toContain('Sportanlage Nippes', 'Tennisclub Süd');
    expect($courtResults->pluck('name')->all())->not->toContain('Tennisplatz');
});

test('a mapped sports centre context lists only its contained facilities', function () {
    $complex = Spot::factory()->create([
        'name' => 'Sportanlage Nippes',
        'category' => 'sports_centre',
        'lat' => 50.964,
        'lng' => 6.954,
    ]);
    $containedCourt = Spot::factory()->create([
        'name' => 'Tennisplatz',
        'category' => 'tennis',
        'parent_spot_id' => $complex->id,
        'lat' => 50.969,
        'lng' => 6.954,
    ]);
    Spot::factory()->create([
        'name' => 'Nearby but separate court',
        'category' => 'basketball',
        'lat' => 50.9642,
        'lng' => 6.9541,
    ]);

    $nearby = collect($this->getJson("/api/places/{$complex->id}/context")->json('nearby'));

    expect($nearby->pluck('id')->all())->toBe([$containedCourt->id]);
});

test('culture places are listed with the culture coarse bucket', function () {
    Spot::factory()->create(['name' => 'Museum Ludwig', 'category' => 'museum', 'veedel' => 'Altstadt-Nord', 'lat' => 50.9403, 'lng' => 6.9602, 'tags' => ['fee' => 'no']]);

    $data = $this->getJson('/api/places?category=culture')->json('data');

    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Museum Ludwig');
    expect($data[0]['category'])->toBe('culture');
    expect($data[0]['fine_label'])->toBe('Museum');
    expect($data[0]['emoji'])->toBe('🏛️');
    expect($data[0]['price_text'])->toBe('free');
});

test('named destinations rank above generic facilities', function () {
    // The commodity facility is closer to home, the named park farther
    Spot::factory()->create(['name' => 'Tischtennisplatte', 'category' => 'table_tennis', 'lat' => 50.948, 'lng' => 6.921]);
    Spot::factory()->create(['name' => 'Blücherpark', 'category' => 'park', 'lat' => 50.962, 'lng' => 6.930]);

    $names = collect($this->getJson('/api/places')->json('data'))->pluck('name')->all();

    expect($names)->toBe(['Blücherpark', 'Tischtennisplatte']);
});

test("a park's context lists the facilities inside it", function () {
    Http::fake();

    $park = Spot::factory()->create(['name' => 'Blücherpark', 'category' => 'park', 'lat' => 50.962, 'lng' => 6.930]);
    Spot::factory()->create(['name' => 'Bolzplatz', 'category' => 'pitch', 'park_name' => 'Blücherpark', 'lat' => 50.9685, 'lng' => 6.930]); // ~700m, still inside
    Spot::factory()->create(['name' => 'Spielplatz', 'category' => 'playground', 'park_name' => null, 'lat' => 50.9621, 'lng' => 6.9301]); // close but outside

    $nearby = $this->getJson("/api/places/{$park->id}/context")->json('nearby');

    expect(collect($nearby)->pluck('name')->all())->toBe(['Bolzplatz']);
});

test('shows a single place with the full card contract', function () {
    $spot = Spot::factory()->create([
        'name' => 'Grüngürtel court',
        'category' => 'basketball',
        'veedel' => 'Ehrenfeld',
        'lat' => 50.949,
        'lng' => 6.922,
        'tags' => ['surface' => 'asphalt'],
    ]);
    // Cluster sibling at the same corner
    Spot::factory()->create(['name' => 'Grüngürtel court', 'category' => 'basketball', 'veedel' => 'Ehrenfeld', 'lat' => 50.9491, 'lng' => 6.9221]);

    // A live fix in the request is the origin (home no longer anchors distance).
    $response = $this->getJson("/api/places/{$spot->id}?lat=50.948&lng=6.921");

    $response->assertOk();
    $response->assertJsonPath('data.id', $spot->id);
    $response->assertJsonPath('data.category', 'court');
    $response->assertJsonPath('data.cluster_size', 2);
    expect($response->json('data.distance_min'))->toBeNull()
        ->and($response->json('data.distance_km'))->toBeGreaterThan(0.0);
    expect(collect($response->json('data.facts'))->pluck('label')->all())->toContain('surface');
});

test('context prefers facilities in the same park over the 300m radius', function () {
    Http::fake();

    $court = Spot::factory()->create(['name' => 'Basketballplatz', 'category' => 'basketball', 'park_name' => 'Blücherpark', 'lat' => 50.962, 'lng' => 6.930]);
    // Same park but ~700m away → still listed (the park is the venue)
    $farPitch = Spot::factory()->create(['name' => 'Bolzplatz', 'category' => 'pitch', 'park_name' => 'Blücherpark', 'lat' => 50.9685, 'lng' => 6.930]);
    // 100m away but NOT in the park → excluded in park mode
    Spot::factory()->create(['name' => 'Spielplatz', 'category' => 'playground', 'park_name' => null, 'lat' => 50.9621, 'lng' => 6.9312]);

    $nearby = $this->getJson("/api/places/{$court->id}/context")->json('nearby');

    expect(collect($nearby)->pluck('id')->all())->toBe([$farPitch->id]);
});

test('places inside a park carry the park name', function () {
    // In-park facilities are reached via the detail (park hop), not the list
    $pitch = Spot::factory()->create(['name' => 'Bolzplatz', 'category' => 'pitch', 'veedel' => 'Neuehrenfeld', 'park_name' => 'Blücherpark', 'lat' => 50.962, 'lng' => 6.930]);

    $this->getJson("/api/places/{$pitch->id}")->assertJsonPath('data.park', 'Blücherpark');
});

test('place context lists nearby places, excluding same-name siblings', function () {
    Http::fake();

    $spot = Spot::factory()->create(['name' => 'Court', 'category' => 'basketball', 'lat' => 50.948, 'lng' => 6.921]);
    // Same name nearby = cluster sibling, not "also around here"
    Spot::factory()->create(['name' => 'Court', 'category' => 'basketball', 'lat' => 50.9481, 'lng' => 6.9211]);
    // Different place ~80m away → listed with a walk time
    Spot::factory()->create(['name' => 'Spielplatz', 'category' => 'playground', 'lat' => 50.9485, 'lng' => 6.9215]);
    // Too far (>300m) → excluded
    Spot::factory()->create(['name' => 'Blücherpark', 'category' => 'park', 'lat' => 50.96, 'lng' => 6.95]);

    $response = $this->getJson("/api/places/{$spot->id}/context");

    $response->assertOk();
    expect(collect($response->json('nearby'))->pluck('name')->all())->toBe(['Spielplatz']);
    expect($response->json('nearby.0.walk_min'))->toBeGreaterThanOrEqual(1);
    expect($response->json('nearby.0.emoji'))->toBe('🛝');
});

test('rejects an unknown coarse category', function () {
    $this->getJson('/api/places?category=nightclub')->assertUnprocessable();
});

test('orders by distance from the resolved origin', function () {
    $near = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    $far = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.99, 'lng' => 7.00]);

    // A live fix next to $near orders it ahead of $far.
    $ids = collect($this->getJson('/api/places?lat=50.949&lng=6.922')->json('data'))->pluck('id')->all();
    expect(array_search($near->id, $ids))->toBeLessThan(array_search($far->id, $ids));
});

test('distance_min uses the real travel matrix, not the heuristic', function () {
    // Isolate the controller: the matrix's HTTP/failover path is covered in
    // FailoverTest. Here we prove the controller applies its result.
    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('travelMatrix')->andReturn([9]); // 9 min by bike
    });

    Spot::factory()->create(['name' => 'Bike park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);

    // A live fix gives the list an origin to measure from.
    $data = $this->getJson('/api/places?veedel=Ehrenfeld&lat=50.948&lng=6.921')->json('data');

    expect($data[0]['distance_min'])->toBe(9);
});

test('no transport preference picks the fastest available direct matrix time', function () {
    $calls = [];
    $this->mock(RouteService::class, function ($mock) use (&$calls) {
        $mock->shouldReceive('travelMatrix')->andReturnUsing(function ($origin, $destinations, $mode) use (&$calls) {
            $calls[] = $mode;

            return $mode === 'WALK' ? [null] : [40];
        });
    });
    Spot::factory()->create(['name' => 'Across the river', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 51.0573, 'lng' => 6.9224]);

    $place = $this->getJson('/api/places?veedel=Ehrenfeld&lat=51.0231&lng=6.9499')->json('data.0');

    expect($place['distance_min'])->toBe(40)
        ->and($place['distance_mode'])->toBe('bike')
        ->and($calls)->toBe(['WALK', 'BIKE']);
});

test('transit preference does not label a bike proxy as transit time', function () {
    $this->user->update(['transport_mode' => 'transit']);
    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldNotReceive('travelMatrix');
    });
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 51.0573, 'lng' => 6.9224]);

    $place = $this->getJson('/api/places?veedel=Ehrenfeld&lat=51.0231&lng=6.9499')->json('data.0');

    expect($place['distance_min'])->toBeNull()
        ->and($place['distance_mode'])->toBe('transit')
        ->and($place['distance_km'])->toBeGreaterThan(4.0);
});

test('the list measures distance in the user transport mode', function () {
    $this->user->update(['transport_mode' => 'walk']);

    $modes = [];
    $this->mock(RouteService::class, function ($mock) use (&$modes) {
        $mock->shouldReceive('travelMatrix')->andReturnUsing(function ($origin, $destinations, $mode = 'BIKE') use (&$modes) {
            $modes[] = $mode;

            return array_fill(0, count($destinations), 7); // short walks → no bike fallback
        });
    });

    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);

    $this->getJson('/api/places?veedel=Ehrenfeld&lat=50.948&lng=6.921')->assertOk();

    expect($modes)->toContain('WALK');
});

test('the response carries the resolved origin for the From control', function () {
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);

    // A live fix → origin source 'live'.
    $this->getJson('/api/places?lat=50.948&lng=6.921')
        ->assertOk()
        ->assertJsonPath('origin.source', 'live');

    // No location, browsing all Cologne → none (never a guessed centre).
    Redis::del("confirmed_location:{$this->user->id}");
    Redis::del("location_history:{$this->user->id}");
    $this->getJson('/api/places')->assertJsonPath('origin.source', 'none');
});

test('near mode lists places within a radius of the user, closest-first', function () {
    app(UserLocationService::class)->confirm($this->user, 50.95, 6.92, 'Here');
    Spot::factory()->create(['name' => 'Spielplatz am Weg', 'category' => 'playground', 'lat' => 50.9501, 'lng' => 6.9201]);
    Spot::factory()->create(['name' => 'Close park', 'category' => 'park', 'lat' => 50.951, 'lng' => 6.921]);
    // Two more within 3km so the radius needn't widen past the far one.
    Spot::factory()->create(['name' => 'Close park 2', 'category' => 'park', 'lat' => 50.952, 'lng' => 6.922]);
    Spot::factory()->create(['name' => 'Close park 3', 'category' => 'park', 'lat' => 50.953, 'lng' => 6.918]);
    Spot::factory()->create(['name' => 'Far park', 'category' => 'park', 'veedel' => 'Porz', 'lat' => 50.88, 'lng' => 7.06]);

    $names = collect($this->getJson('/api/places?near=1')->json('data'))->pluck('name')->all();

    expect($names[0])->toBe('Spielplatz am Weg')
        ->and($names)->toContain('Close park');
    expect($names)->not->toContain('Far park'); // 3 nearby exist → ring stays tight, far stays out
});

test('near mode with no location flags needs_location and returns nothing', function () {
    Redis::del("confirmed_location:{$this->user->id}");
    Redis::del("location_history:{$this->user->id}");

    $response = $this->getJson('/api/places?near=1');

    $response->assertOk()->assertJsonPath('needs_location', true);
    expect($response->json('data'))->toBe([]);
});

test('a confirmed location drives distance ordering', function () {
    // A stale fix from another test must not skew the baseline.
    Redis::del("confirmed_location:{$this->user->id}");
    Redis::del("location_history:{$this->user->id}");

    $byEhrenfeld = Spot::factory()->create(['name' => 'By Ehrenfeld', 'category' => 'park', 'lat' => 50.949, 'lng' => 6.922]);
    $acrossTown = Spot::factory()->create(['name' => 'Across town', 'category' => 'park', 'lat' => 50.99, 'lng' => 7.05]);

    // Stand the user next to the across-town park (name set, so confirm skips
    // the reverse-geocode network call).
    app(UserLocationService::class)->confirm($this->user, 50.99, 7.05, 'Across town');

    // Distances are measured from the confirmed fix → across-town ranks first.
    $ids = collect($this->getJson('/api/places')->json('data'))->pluck('id')->all();
    expect(array_search($acrossTown->id, $ids))->toBeLessThan(array_search($byEhrenfeld->id, $ids));
});

test('the show endpoint exposes the viewer’s feedback state and rating', function () {
    $spot = Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    SpotFeedback::factory()->been('up')->create(['user_id' => $this->user->id, 'spot_id' => $spot->id]);

    $response = $this->getJson("/api/places/{$spot->id}");

    $response->assertOk();
    $response->assertJsonPath('data.feedback_state', 'been');
    $response->assertJsonPath('data.feedback_rating', 'up');
});

test('the show endpoint reports null feedback when the viewer has none', function () {
    $spot = Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    // Another user's feedback must not leak into this viewer's response.
    SpotFeedback::factory()->been('down')->create(['spot_id' => $spot->id]);

    $response = $this->getJson("/api/places/{$spot->id}");

    $response->assertJsonPath('data.feedback_state', null);
    $response->assertJsonPath('data.feedback_rating', null);
});

test('the list carries feedback state and hides not-interested places', function () {
    $saved = Spot::factory()->create(['name' => 'Saved park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.921]);
    $hidden = Spot::factory()->create(['name' => 'Hidden park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.9482, 'lng' => 6.9212]);
    SpotFeedback::factory()->create(['user_id' => $this->user->id, 'spot_id' => $saved->id]); // default: saved
    SpotFeedback::factory()->notInterested()->create(['user_id' => $this->user->id, 'spot_id' => $hidden->id]);

    $data = collect($this->getJson('/api/places?veedel=Ehrenfeld')->json('data'));

    expect($data->pluck('name')->all())->toContain('Saved park');
    expect($data->pluck('name')->all())->not->toContain('Hidden park');
    expect($data->firstWhere('name', 'Saved park')['feedback_state'])->toBe('saved');
});
