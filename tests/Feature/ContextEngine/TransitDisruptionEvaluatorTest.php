<?php

use App\ContextEngine\ActionBus;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Models\UserPlace;
use App\Models\UserRouteCache;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

beforeEach(function () {
    config(['context_engine.shadow' => false, 'context_engine.enabled' => true]);
});

beforeEach(function () {
    // Parallel test processes share Redis; flush this test file's namespace
    // before each test rather than tracking specific user IDs.
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        'pending_actions:*'
    );
});

test('disruption on UserRouteCache line emits a scored action', function () {
    $user = User::factory()->onboarded()->create();
    $home = UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    $work = UserPlace::factory()->create([
        'user_id' => $user->id,
        'category' => 'work',
        'arrive_by' => '09:00',
        'day_mode' => 'weekdays',
    ]);
    UserRouteCache::create([
        'user_id' => $user->id,
        'from_place_id' => $home->id,
        'to_place_id' => $work->id,
        'mode' => 'transit',
        'lines' => ['12', '15'],
        'bbox' => null,
        'typical_window' => ['weekday' => [[7, 9]], 'weekend' => []],
        'computed_at' => now(),
    ]);

    event(new TransitDisruptionDetected(
        disruptionId: 100,
        lines: ['12'],
        stopsAffected: [],
        severity: 'major',
        bbox: null,
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);

    expect($actions)->not->toBeEmpty();
    expect(collect($actions)->pluck('type')->all())->toContain('transit_disruption');
});

test('disruption on unrelated line does not emit action', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);

    event(new TransitDisruptionDetected(
        disruptionId: 200,
        lines: ['99'],
        stopsAffected: [],
        severity: 'major',
        bbox: null,
        expiresAt: null,
    ));

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty();
});

test('repeat disruption with same id is deduped by action_key', function () {
    $user = User::factory()->onboarded()->create();
    $home = UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    $work = UserPlace::factory()->create([
        'user_id' => $user->id,
        'category' => 'work',
        'arrive_by' => '09:00',
        'day_mode' => 'weekdays',
    ]);
    UserRouteCache::create([
        'user_id' => $user->id,
        'from_place_id' => $home->id,
        'to_place_id' => $work->id,
        'mode' => 'transit',
        'lines' => ['12'],
        'bbox' => null,
        'typical_window' => ['weekday' => [[7, 9]], 'weekend' => []],
        'computed_at' => now(),
    ]);

    $payload = [
        'disruptionId' => 300,
        'lines' => ['12'],
        'stopsAffected' => [],
        'severity' => 'major',
        'bbox' => null,
        'expiresAt' => null,
    ];

    event(new TransitDisruptionDetected(...$payload));
    event(new TransitDisruptionDetected(...$payload));

    $disruptions = collect(app(ActionBus::class)->topK($user->id, 10))
        ->where('type', 'transit_disruption');

    expect($disruptions)->toHaveCount(1);
});

test('major disruption on a route_match always lands above severity gate', function () {
    // Verifies the score is high enough to compete with critical-only legacy gate
    $user = User::factory()->onboarded()->create();
    $home = UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    $work = UserPlace::factory()->create([
        'user_id' => $user->id,
        'category' => 'work',
        'arrive_by' => now()->addHour()->format('H:i'),
        'day_mode' => 'weekdays',
    ]);
    UserRouteCache::create([
        'user_id' => $user->id,
        'from_place_id' => $home->id,
        'to_place_id' => $work->id,
        'mode' => 'transit',
        'lines' => ['12'],
        'bbox' => null,
        'typical_window' => [
            'weekday' => [[now()->hour, now()->hour + 2]],
            'weekend' => [[now()->hour, now()->hour + 2]],
        ],
        'computed_at' => now(),
    ]);

    event(new TransitDisruptionDetected(
        disruptionId: 400,
        lines: ['12'],
        stopsAffected: [],
        severity: 'major',
        bbox: null,
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);
    $disruption = collect($actions)->firstWhere('type', 'transit_disruption');

    expect($disruption->score)->toBeGreaterThan(50.0);
});
