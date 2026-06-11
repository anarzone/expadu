<?php

use App\Models\Event;
use App\Models\Spot;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserPlace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

function fakeAnthropicParse(array $input): void
{
    Http::fake([
        'api.anthropic.com/v1/messages' => Http::response([
            'content' => [
                ['type' => 'tool_use', 'name' => 'set_constraints', 'input' => $input],
            ],
        ]),
    ]);
}

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

test('parse returns editable constraints from the LLM tool call', function () {
    $start = now('Europe/Berlin')->addDay()->setTime(14, 0);
    fakeAnthropicParse([
        'window_start' => $start->toIso8601String(),
        'window_end' => $start->addHours(6)->toIso8601String(),
        'areas' => ['Ehrenfeld'],
        'categories' => ['park', 'cafe'],
        'companions' => 'friends',
        'budget' => 'low',
    ]);

    $this->actingAs(composerUser());
    $response = $this->postJson('/composer/parse', ['text' => 'free Saturday afternoon in Ehrenfeld with friends, cheap']);

    $response->assertOk();
    $response->assertJsonPath('constraints.areas.0', 'Ehrenfeld');
    $response->assertJsonPath('constraints.companions', 'friends');
    $response->assertJsonPath('constraints.budget', 'low');
});

test('parse falls back to profile defaults when the LLM fails', function () {
    Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);

    $this->actingAs(composerUser());
    $response = $this->postJson('/composer/parse', ['text' => 'anything']);

    $response->assertOk();
    // Profile defaults: home Veedel's Bezirk
    expect($response->json('constraints.areas'))->toContain('Ehrenfeld');
});

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
    expect(Cache::get("composer:plan:{$user->id}"))->not->toBeNull();
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
