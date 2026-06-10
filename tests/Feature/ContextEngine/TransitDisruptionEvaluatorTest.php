<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\User;
use App\Models\UserPlace;
use App\Services\UserTransitLinesService;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

beforeEach(function () {
    // Push gating is quiet-hours aware (22:00–06:00) and rate-limited per
    // user-id per day in shared Redis. Travel to a unique future daytime so
    // channel assertions are deterministic and throttle keys never collide
    // with other tests reusing recycled user ids.
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
});

beforeEach(function () {
    // Parallel test processes share Redis; flush this test file's namespace
    // before each test rather than tracking specific user IDs. Lua KEYS()
    // sees the database-side keys directly, so prepend the configured prefix.
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'pending_actions:*'
    );
});

function fakeUserLines(array $lines): void
{
    test()->mock(UserTransitLinesService::class, function ($mock) use ($lines) {
        $mock->shouldReceive('getRelevantLines')->andReturn([
            'lines' => collect($lines),
            'stops' => collect(),
            'context' => [],
        ]);
    });
}

test('disruption on a line near user places emits a scored action', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    fakeUserLines(['12', '15']);

    event(new TransitDisruptionDetected(
        disruptionId: 100,
        lines: ['12'],
        stopsAffected: [],
        severity: 'moderate',
        bbox: null,
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);

    expect($actions)->not->toBeEmpty();
    expect(collect($actions)->pluck('type')->all())->toContain('transit_disruption');
});

test('moderate disruption on unrelated line does not emit action', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    fakeUserLines([]);

    event(new TransitDisruptionDetected(
        disruptionId: 200,
        lines: ['99'],
        stopsAffected: [],
        severity: 'moderate',
        bbox: null,
        expiresAt: null,
    ));

    expect(app(ActionBus::class)->topK($user->id, 10))->toBeEmpty();
});

test('major disruption broadcasts to all onboarded users without push', function () {
    $user = User::factory()->onboarded()->create();
    fakeUserLines([]);

    event(new TransitDisruptionDetected(
        disruptionId: 250,
        lines: ['99'],
        stopsAffected: [],
        severity: 'major',
        bbox: null,
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);
    $disruption = collect($actions)->firstWhere('type', 'transit_disruption');

    expect($disruption)->not->toBeNull();
    expect($disruption->deliverChannels)->not->toContain(ScoredAction::CHANNEL_PUSH);
});

test('critical disruption on matched line includes push channel', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    fakeUserLines(['12']);

    event(new TransitDisruptionDetected(
        disruptionId: 260,
        lines: ['12'],
        stopsAffected: [],
        severity: 'critical',
        bbox: null,
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);
    $disruption = collect($actions)->firstWhere('type', 'transit_disruption');

    expect($disruption)->not->toBeNull();
    expect($disruption->deliverChannels)->toContain(ScoredAction::CHANNEL_PUSH);
});

test('moderate disruption with bbox covering a saved place geo-matches', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create([
        'user_id' => $user->id,
        'category' => 'home',
        'lat' => 50.94,
        'lng' => 6.96,
    ]);
    fakeUserLines([]);

    event(new TransitDisruptionDetected(
        disruptionId: 270,
        lines: ['99'],
        stopsAffected: [],
        severity: 'moderate',
        bbox: ['minLat' => 50.9, 'maxLat' => 51.0, 'minLng' => 6.9, 'maxLng' => 7.0],
        expiresAt: null,
    ));

    $actions = app(ActionBus::class)->topK($user->id, 10);

    expect(collect($actions)->pluck('type')->all())->toContain('transit_disruption');
});

test('repeat disruption with same id is deduped by action_key', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    fakeUserLines(['12']);

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

test('major disruption on a matched line scores above the broadcast tier', function () {
    $user = User::factory()->onboarded()->create();
    UserPlace::factory()->create(['user_id' => $user->id, 'category' => 'home']);
    fakeUserLines(['12']);

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

    // major (70) × line match (0.8) × inside window (1.0) × fresh (~1.0) ≈ 56
    expect($disruption->score)->toBeGreaterThan(50.0);
});
