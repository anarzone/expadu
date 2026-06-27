<?php

use App\Composer\TodayPlanStore;
use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserPlace;
use App\Models\UserTask;
use App\Services\UserLocationService;
use App\Services\WeatherService;
use App\Transit\Contracts\RouteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    // No stray HTTP: the composer's one-to-many origin matrix is faked away, so
    // compose falls back to the haversine estimate (as before the MOTIS wiring).
    Http::fake();
});

function composerUser(): User
{
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld', 'situation' => 'student', 'is_eu' => true]);
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'category' => 'home',
        'lat' => 50.9485,
        'lng' => 6.9230,
    ]);
    // An explicit "I'm here" fix is the plan's origin now (home no longer anchors).
    app(UserLocationService::class)->confirm($user, 50.9485, 6.9230, 'Home');

    return $user;
}

// ── parse: intent routing (heuristic — no key, no network) ───────────────

test('parse classifies a leisure prompt as plan_day with editable constraints', function () {
    $this->actingAs(composerUser());

    $response = $this->postJson('/composer/parse', [
        'text' => 'tomorrow afternoon in Ehrenfeld with friends',
    ]);

    $response->assertOk();
    $response->assertJsonPath('intent', 'plan_day');
    $response->assertJsonPath('source', 'heuristic');
    $response->assertJsonPath('constraints.companions', 'friends');
    expect($response->json('constraints.areas'))->toContain('Ehrenfeld');
});

test('parse routes a paperwork question to bureaucracy, not a plan', function () {
    $this->actingAs(composerUser());

    $response = $this->postJson('/composer/parse', [
        'text' => 'do I need an appointment for Anmeldung?',
    ]);

    $response->assertOk();
    $response->assertJsonPath('intent', 'bureaucracy_q');
    $response->assertJsonPath('constraints', null);
    expect($response->json('query'))->not->toBeNull();
});

test('parse routes a place search to find', function () {
    $this->actingAs(composerUser());

    $response = $this->postJson('/composer/parse', [
        'text' => 'basketball court near Ehrenfeld',
    ]);

    $response->assertOk();
    $response->assertJsonPath('intent', 'find');
});

// ── compose / swap ───────────────────────────────────────────────────────

test('compose builds a plan from real candidates and stores it', function () {
    $user = composerUser();

    Spot::factory()->count(4)->create([
        'category' => 'cafe',
        'lat' => 50.9480,
        'lng' => 6.9240,
    ]);
    Event::factory()->create([
        'title' => 'Expat Mixer',
        'starts_at' => now('Europe/Berlin')->addDay()->setTime(18, 0),
        'ends_at' => now('Europe/Berlin')->addDay()->setTime(20, 0),
    ]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'areas' => [],
            'categories' => [],
        ],
    ]);

    $response->assertOk();
    expect($response->json('plan.slots'))->not->toBeEmpty();
    expect($response->json('notices'))->toBeArray();
    expect(Cache::get("composer:plan:{$user->id}"))->not->toBeNull();
});

test('compose carries a dry weather line for a plan today, not in the notices', function () {
    $user = composerUser();
    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->andReturn([
            'available' => true,
            'rain_soon' => false,
            'rain_summary' => 'Dry next 8 hours',
        ]);
        $m->shouldReceive('getCurrentWeather')->andReturn([
            'available' => true,
            'temperature' => 22,
            'condition' => 'Clear',
        ]);
    });

    $this->actingAs($user);
    $start = now('Europe/Berlin')->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->setTime(19, 0)->toIso8601String(),
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('weather.rain', false);
    expect($response->json('weather.text'))->toContain('22°')->toContain('afternoon');
    // Weather is the dedicated cyan line, never duplicated in the notice pills.
    expect(collect($response->json('notices'))->pluck('text')->implode(' '))->not->toContain('22°');
});

test('compose frames a rainy plan with the rain summary on the weather line', function () {
    $user = composerUser();
    $this->mock(WeatherService::class, function ($m) {
        $m->shouldReceive('getForecast')->andReturn([
            'available' => true,
            'rain_soon' => true,
            'rain_summary' => 'Rain from 15:00',
        ]);
        $m->shouldReceive('getCurrentWeather')->andReturn(['available' => true, 'temperature' => 14, 'condition' => 'Rain']);
    });

    $this->actingAs($user);
    $start = now('Europe/Berlin')->setTime(13, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->setTime(18, 0)->toIso8601String(),
        ],
    ]);

    $response->assertOk();
    $response->assertJsonPath('weather.rain', true);
    expect($response->json('weather.text'))->toBe('Rain from 15:00');
});

test('save pins the composed plan to Today, surfaces it on the home feed, and clear removes it', function () {
    $user = composerUser();
    Spot::factory()->count(3)->create(['category' => 'cafe', 'lat' => 50.9480, 'lng' => 6.9240]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->setTime(14, 0);
    $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->setTime(19, 0)->toIso8601String(),
            'areas' => [],
            'categories' => [],
        ],
    ])->assertOk();

    // Composing alone doesn't pin anything to Today.
    expect(app(TodayPlanStore::class)->get($user))->toBeNull();

    $this->postJson('/composer/save', ['prompt' => 'something this afternoon'])
        ->assertOk()
        ->assertJsonPath('saved', true);

    $saved = app(TodayPlanStore::class)->get($user);
    expect($saved)->not->toBeNull();
    expect($saved['prompt'])->toBe('something this afternoon');
    expect($saved['slots'])->not->toBeEmpty();

    // The home feed now carries it as a prop.
    $this->get('/dashboard')->assertInertia(fn ($page) => $page
        ->component('dashboard')
        ->where('savedPlan.prompt', 'something this afternoon')
        ->has('savedPlan.slots'),
    );

    $this->deleteJson('/composer/today')->assertOk();
    expect(app(TodayPlanStore::class)->get($user))->toBeNull();
});

test('save without a composed plan is a 404, not a crash', function () {
    $this->actingAs(composerUser());

    $this->postJson('/composer/save', ['prompt' => 'anything'])->assertNotFound();
});

test('compose returns adaptive facets for the editable filters', function () {
    $user = composerUser();

    Spot::factory()->create(['category' => 'museum', 'veedel' => 'Ehrenfeld', 'lat' => 50.948, 'lng' => 6.924]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Nippes', 'lat' => 50.96, 'lng' => 6.95]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'areas' => [],
            'categories' => [],
        ],
    ]);

    $response->assertOk();
    // Category options are the coarse buckets present (museum → culture), labelled.
    $categories = collect($response->json('facets.categories'));
    expect($categories->pluck('value'))->toContain('culture')->toContain('park');
    expect($categories->firstWhere('value', 'culture')['label'])->toBe('Culture');
    // Area options list neighbourhoods that actually have candidates.
    expect($response->json('facets.areas'))->toContain('Ehrenfeld');
});

test('compose honours an explicit archetype', function () {
    $user = composerUser();
    Spot::factory()->create(['name' => 'Big Museum', 'category' => 'museum', 'lat' => 50.9480, 'lng' => 6.9240]);
    Spot::factory()->create(['name' => 'Green Park', 'category' => 'park', 'lat' => 50.9481, 'lng' => 6.9241]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'archetype' => 'chill', // ChillNearby excludes culture
        ],
    ]);

    $response->assertOk();
    expect($response->json('plan.slots'))->not->toBeEmpty();
    expect(collect($response->json('plan.slots'))->pluck('category'))->not->toContain('museum');
});

test('compose excludes a removed pick', function () {
    $user = composerUser();
    Spot::factory()->create(['name' => 'Keep Park', 'category' => 'park', 'lat' => 50.9480, 'lng' => 6.9240]);
    $remove = Spot::factory()->create(['name' => 'Remove Park', 'category' => 'park', 'lat' => 50.9481, 'lng' => 6.9241]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
        ],
        'excluded' => ["spot:{$remove->id}"],
    ]);

    $response->assertOk();
    expect(collect($response->json('plan.slots'))->pluck('id'))->not->toContain("spot:{$remove->id}");
});

test('a nightlife interest steers the composed plan toward a bar', function () {
    // 'other' situation gives bars no default boost — so the bar leading the
    // plan is the interest talking, not the situation.
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => true,
        'veedel' => 'Ehrenfeld',
        'interests' => ['nightlife'],
    ]);
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home', 'lat' => 50.9485, 'lng' => 6.9230]);
    app(UserLocationService::class)->confirm($user, 50.9485, 6.9230, 'Home');
    Spot::factory()->create(['name' => 'Late Bar', 'category' => 'bar', 'lat' => 50.9480, 'lng' => 6.9240]);
    Spot::factory()->create(['name' => 'Quiet Cafe', 'category' => 'cafe', 'lat' => 50.9481, 'lng' => 6.9241]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
        ],
    ]);

    $response->assertOk();
    expect($response->json('plan.slots.0.category'))->toBe('bar');
});

test('the why lines survive a swap', function () {
    $user = composerUser();
    Spot::factory()->create(['name' => 'Famous Museum', 'category' => 'museum', 'lat' => 50.9480, 'lng' => 6.9240, 'tags' => ['wikidata' => 'Q1']]);
    Spot::factory()->count(8)->create(['category' => 'cafe', 'lat' => 50.9481, 'lng' => 6.9241]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $compose = $this->postJson('/composer/compose', ['constraints' => [
        'window_start' => $start->toIso8601String(),
        'window_end' => $start->addHours(6)->toIso8601String(),
    ]]);
    $compose->assertOk();

    // Swap a café (not the landmark) so the museum's "⭐ landmark" why persists.
    $idx = collect($compose->json('plan.slots'))
        ->search(fn ($s) => $s['swappable'] && $s['category'] === 'cafe');

    $swap = $this->postJson('/composer/swap', ['slot' => $idx]);

    $swap->assertOk();
    expect(collect($swap->json('plan.slots'))->pluck('why')->filter()->values())->not->toBeEmpty();
});

test('a removed pick does not return via swap', function () {
    $user = composerUser();
    Spot::factory()->count(8)->create(['category' => 'cafe', 'lat' => 50.9480, 'lng' => 6.9240]);
    $removed = Spot::factory()->create(['name' => 'Removed Cafe', 'category' => 'cafe', 'lat' => 50.9481, 'lng' => 6.9241]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $this->postJson('/composer/compose', [
        'constraints' => ['window_start' => $start->toIso8601String(), 'window_end' => $start->addHours(6)->toIso8601String()],
        'excluded' => ["spot:{$removed->id}"],
    ])->assertOk();

    $swap = $this->postJson('/composer/swap', ['slot' => 0]);

    $swap->assertOk();
    expect(collect($swap->json('plan.slots'))->pluck('id'))->not->toContain("spot:{$removed->id}");
});

test('compose weaves a booked appointment as an immovable anchor', function () {
    Http::fake([
        'photon.komoot.io/*' => Http::response([
            'features' => [
                ['geometry' => ['coordinates' => [7.0009, 50.9416]], 'properties' => ['name' => 'Ausländerbehörde']],
            ],
        ]),
        '*' => Http::response(), // MOTIS origin matrix → empty → haversine
    ]);

    $user = composerUser();
    Spot::factory()->count(3)->create(['category' => 'cafe', 'lat' => 50.948, 'lng' => 6.924]);

    $task = Task::factory()->create([
        'booking_service_key' => 'auslaenderbehoerde',
        'documents_required' => ['Passport', 'Biometric photo', 'Application form'],
        'title' => 'Residence permit appointment',
    ]);
    UserTask::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'appointment_at' => now('Europe/Berlin')->addDay()->setTime(14, 0),
    ]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(10, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->setTime(19, 0)->toIso8601String(),
        ],
    ]);

    $response->assertOk();
    $anchor = collect($response->json('plan.slots'))->firstWhere('is_appointment', true);

    expect($anchor)->not->toBeNull();
    expect($anchor['swappable'])->toBeFalse();
    expect($anchor['start_time'])->toBe('14:00');
    expect($anchor['subtitle'])->toContain('Ausländerbehörde');
    expect($anchor['subtitle'])->toContain('3 documents');
    expect(collect($response->json('notices'))->pluck('text')->implode(' '))
        ->toContain('appointment');
});

test('an appointment anchor refuses to swap', function () {
    Http::fake(['photon.komoot.io/*' => Http::response(['features' => []]), '*' => Http::response()]);

    $user = composerUser();
    Spot::factory()->count(3)->create(['category' => 'cafe', 'lat' => 50.948, 'lng' => 6.924]);

    $task = Task::factory()->create(['booking_service_key' => 'auslaenderbehoerde', 'title' => 'Permit appointment']);
    UserTask::factory()->create([
        'user_id' => $user->id,
        'task_id' => $task->id,
        'appointment_at' => now('Europe/Berlin')->addDay()->setTime(14, 0),
    ]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(10, 0);
    $compose = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->setTime(19, 0)->toIso8601String(),
        ],
    ]);
    $compose->assertOk();

    $anchorIndex = collect($compose->json('plan.slots'))->search(fn ($s) => $s['is_appointment'] === true);
    expect($anchorIndex)->not->toBeFalse();

    $this->postJson('/composer/swap', ['slot' => $anchorIndex])->assertUnprocessable();
    // The refusal must not record a negative intent signal.
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'composer_swap_away')->exists())->toBeFalse();
});

test('swap exchanges one slot and records the negative signal', function () {
    $user = composerUser();

    Spot::factory()->count(8)->sequence(fn ($seq) => [
        'name' => "Cafe {$seq->index}",
        'category' => 'cafe',
        'lat' => 50.9480 + $seq->index * 0.001,
        'lng' => 6.9240,
    ])->create();

    $this->actingAs($user);
    // 8h window: the 6-slot cap leaves spares AND end-of-window slack,
    // so swapping the last slot always has a fitting alternative.
    $start = now('Europe/Berlin')->addDay()->setTime(12, 0);
    $compose = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(8)->toIso8601String(),
        ],
    ]);
    $compose->assertOk();
    $slotCount = count($compose->json('plan.slots'));
    expect($slotCount)->toBeGreaterThan(0);
    $lastIndex = $slotCount - 1;
    $originalId = $compose->json("plan.slots.{$lastIndex}.id");

    $swap = $this->postJson('/composer/swap', ['slot' => $lastIndex]);

    $swap->assertOk();
    expect($swap->json("plan.slots.{$lastIndex}.id"))->not->toBe($originalId);
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'composer_swap_away')->exists())->toBeTrue();
});

test('the shops-shut notice respects the German week — Sunday, not Saturday', function () {
    $this->actingAs(composerUser());

    // 2026-06-13 is a Saturday: shops are OPEN today; only nudge about
    // tomorrow (Sunday). The false "today shops shut" must not appear.
    $sat = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => '2026-06-13T12:00:00+02:00',
            'window_end' => '2026-06-13T18:00:00+02:00',
        ],
    ]);
    $satText = collect($sat->json('notices'))->pluck('text')->implode(' | ');
    expect($satText)->not->toContain('most shops shut');
    expect($satText)->toContain('Grab groceries');

    // 2026-06-14 is a Sunday: today's closure notice fires.
    $sun = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => '2026-06-14T12:00:00+02:00',
            'window_end' => '2026-06-14T18:00:00+02:00',
        ],
    ]);
    expect(collect($sun->json('notices'))->pluck('text')->implode(' | '))
        ->toContain('most shops shut');
});

test('a pinned spot is anchored into the plan even when it ranks low', function () {
    $user = composerUser();

    // A low-rated pinned spot vs high-rated filler: only the pin boost
    // should pull it into the plan.
    $pinned = Spot::factory()->create(['name' => 'Pinned Lake', 'category' => 'lake', 'lat' => 50.95, 'lng' => 6.93, 'rating' => 1.0]);
    Spot::factory()->count(5)->create(['category' => 'cafe', 'lat' => 50.948, 'lng' => 6.924, 'rating' => 5.0]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(12, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->copy()->addHours(6)->toIso8601String(),
        ],
        'pins' => ["spot:{$pinned->id}"],
    ]);

    $response->assertOk();
    expect(collect($response->json('plan.slots'))->pluck('id'))->toContain("spot:{$pinned->id}");
});

test('swap without a stored plan 404s', function () {
    $this->actingAs(composerUser());

    $this->postJson('/composer/swap', ['slot' => 0])->assertNotFound();
});

test('compose rejects windows beyond 72 hours', function () {
    $this->actingAs(composerUser());

    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => now()->toIso8601String(),
            'window_end' => now()->addDays(5)->toIso8601String(),
        ],
    ]);

    $response->assertUnprocessable();
});

test('a thin filter combination still returns a plan instead of nothing', function () {
    $user = composerUser();

    // The Merkenich + pitches + "make a day of it" combination from the bug
    // report: a shaped day with no hero/meal/wind-down to fill. It must not
    // dead-end at "nothing fits".
    Spot::factory()->create(['name' => 'Bolzplatz Merkenich', 'category' => 'pitch', 'veedel' => 'Merkenich', 'lat' => 51.039, 'lng' => 6.930]);
    Spot::factory()->create(['name' => 'Sportplatz Fühlingen', 'category' => 'pitch', 'veedel' => 'Merkenich', 'lat' => 51.041, 'lng' => 6.932]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'areas' => ['Merkenich'],
            'categories' => ['pitch'],
            'budget' => 'free',
            'archetype' => 'make_a_day',
        ],
    ]);

    $response->assertOk();
    expect($response->json('plan.slots'))->not->toBeEmpty();
    expect(collect($response->json('notices'))->pluck('text')->implode(' '))->toContain('widened');
});

test('compose starts the plan from the resolved origin, not home', function () {
    $user = composerUser(); // confirmed fix at 50.9485, 6.9230
    Spot::factory()->count(3)->create(['category' => 'cafe', 'lat' => 50.948, 'lng' => 6.924]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
        ],
    ]);

    $response->assertOk()->assertJsonPath('origin.source', 'confirmed');
});

test('the default search area follows the origin Veedel, not home', function () {
    DB::table('veedels')->insert([
        ['name' => 'Ehrenfeld', 'bezirk' => 'Ehrenfeld', 'centroid_lat' => 50.9503, 'centroid_lng' => 6.9113, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'Sülz', 'bezirk' => 'Lindenthal', 'centroid_lat' => 50.9180, 'centroid_lng' => 6.9270, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Home is Ehrenfeld, but the confirmed fix is in Sülz — the plan must favour
    // the Veedel the user is actually starting from, not their home.
    $user = User::factory()->onboarded()->create(['veedel' => 'Ehrenfeld', 'situation' => 'student', 'is_eu' => true]);
    app(UserLocationService::class)->confirm($user, 50.9180, 6.9270, 'Sülz');

    // Two identical parks at the same spot: only the origin-area match separates
    // them, so the one in the origin's Veedel must lead.
    $sulz = Spot::factory()->create(['name' => 'Sülz Park', 'category' => 'park', 'veedel' => 'Sülz', 'lat' => 50.9180, 'lng' => 6.9270]);
    Spot::factory()->create(['name' => 'Ehrenfeld Park', 'category' => 'park', 'veedel' => 'Ehrenfeld', 'lat' => 50.9180, 'lng' => 6.9270]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'areas' => [],
            'categories' => [],
        ],
    ]);

    $response->assertOk()->assertJsonPath('origin.area', 'Sülz');
    expect($response->json('plan.slots.0.id'))->toBe("spot:{$sulz->id}");
});

test('an explicitly picked start area drives the origin and the search area', function () {
    DB::table('veedels')->insert([
        'name' => 'Nippes', 'bezirk' => 'Nippes', 'centroid_lat' => 50.9600, 'centroid_lng' => 6.9500,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $user = composerUser(); // home + confirmed fix both in Ehrenfeld

    Spot::factory()->create(['category' => 'park', 'veedel' => 'Nippes', 'lat' => 50.96, 'lng' => 6.95]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
        ],
        'from_area' => 'Nippes',
    ]);

    // The explicit pick overrides the confirmed fix → origin and area are Nippes.
    $response->assertOk()
        ->assertJsonPath('origin.source', 'area')
        ->assertJsonPath('origin.area', 'Nippes');

    // …and it's remembered as the default area for next time / other surfaces.
    expect($user->fresh()->veedel)->toBe('Nippes');
});

test('with no live location the plan falls back to the remembered area', function () {
    DB::table('veedels')->insert([
        'name' => 'Nippes', 'bezirk' => 'Nippes', 'centroid_lat' => 50.9600, 'centroid_lng' => 6.9500,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Remembered area is Nippes; no confirm() → no live fix this session.
    $user = User::factory()->onboarded()->create(['veedel' => 'Nippes', 'situation' => 'student', 'is_eu' => true]);
    Spot::factory()->create(['category' => 'park', 'veedel' => 'Nippes', 'lat' => 50.96, 'lng' => 6.95]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
            'areas' => [],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('origin.source', 'area')
        ->assertJsonPath('origin.area', 'Nippes');
});

test('the origin leg uses the real travel matrix in the composer', function () {
    // One candidate + a mocked one-to-many → the placed slot's travel-from-
    // origin is the real 42 min, not the haversine estimate (~a few min).
    $this->mock(RouteService::class, function ($mock) {
        $mock->shouldReceive('travelMatrix')->andReturn([42]);
    });

    $user = composerUser(); // confirmed fix is the origin
    Spot::factory()->create(['name' => 'Solo Park', 'category' => 'park', 'lat' => 50.951, 'lng' => 6.93]);

    $this->actingAs($user);
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    $response = $this->postJson('/composer/compose', [
        'constraints' => [
            'window_start' => $start->toIso8601String(),
            'window_end' => $start->addHours(6)->toIso8601String(),
        ],
    ]);

    $response->assertOk();
    expect($response->json('plan.slots.0.travel_min_from_previous'))->toBe(42);
});
