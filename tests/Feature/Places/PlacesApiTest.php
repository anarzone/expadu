<?php

use App\Models\Spot;
use App\Models\SpotFeedback;
use App\Models\User;
use App\Models\UserPlace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
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
        'data' => [['id', 'name', 'category', 'veedel', 'lat', 'lng', 'photo_url', 'distance_min', 'open_now', 'opening_hours_text', 'price_text', 'feature_chips', 'tip', 'transit_hint', 'facts']],
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

    // In the selected Veedel, but farther from the user's home anchor…
    $inVeedel = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.941, 'lng' => 6.905]);
    // …than this neighbouring-Veedel place ~1km from the Ehrenfeld centroid
    $nearby = Spot::factory()->create(['category' => 'park', 'veedel' => 'Neuehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    // Other side of the city, well outside 2km → excluded
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Porz', 'lat' => 50.886, 'lng' => 7.058]);

    $response = $this->getJson('/api/places?veedel=Ehrenfeld');

    expect($response->json('meta.total'))->toBe(2);
    expect($response->json('nearby_included'))->toBeTrue();
    // The selected Veedel's own places rank above nearby ones, even when
    // the nearby place is closer to the user's home.
    expect($response->json('data.0.id'))->toBe($inVeedel->id);
    expect($response->json('data.1.id'))->toBe($nearby->id);
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

    $response = $this->getJson("/api/places/{$spot->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $spot->id);
    $response->assertJsonPath('data.category', 'court');
    $response->assertJsonPath('data.cluster_size', 2);
    expect($response->json('data.distance_min'))->toBeGreaterThanOrEqual(1);
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

test('orders by distance from the home anchor', function () {
    $near = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.949, 'lng' => 6.922]);
    $far = Spot::factory()->create(['category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.99, 'lng' => 7.00]);

    $ids = collect($this->getJson('/api/places')->json('data'))->pluck('id')->all();
    expect(array_search($near->id, $ids))->toBeLessThan(array_search($far->id, $ids));
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
