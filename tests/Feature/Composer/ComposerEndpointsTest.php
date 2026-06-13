<?php

use App\Models\Event;
use App\Models\Spot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserPlace;
use App\Models\UserTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
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

test('compose weaves a booked appointment as an immovable anchor', function () {
    Http::fake([
        'photon.komoot.io/*' => Http::response([
            'features' => [
                ['geometry' => ['coordinates' => [7.0009, 50.9416]], 'properties' => ['name' => 'Ausländerbehörde']],
            ],
        ]),
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
    Http::fake(['photon.komoot.io/*' => Http::response(['features' => []])]);

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
