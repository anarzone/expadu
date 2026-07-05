<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Home\TileComposer;
use App\Home\TileTriage;
use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // Triage keys + bus actions live in raw Redis and would leak between tests
    // (the suite reuses climbing user ids), so clear both up front.
    $prefix = (string) config('database.redis.options.prefix', '');
    foreach (['tile_triage:*', 'pending_actions:*'] as $pattern) {
        Redis::eval(
            "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
            0,
            $prefix.$pattern
        );
    }
});

/** An overdue Anmeldung deadline → a synthetic tile keyed "task:{userTask}". */
function overdueDeadline(User $user): UserTask
{
    $task = Task::factory()->create([
        'title' => 'Register your address (Anmeldung)',
        'situation' => [$user->situation->value],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 5,
    ]);

    return UserTask::create(['user_id' => $user->id, 'task_id' => $task->id, 'is_applicable' => true]);
}

/** A dashboard-channel bus action with a chosen severity (drives the tile tone). */
function triageBusAction(string $type, float $score, string $severity, array $payload): ScoredAction
{
    return new ScoredAction(
        type: $type,
        actionKey: "{$type}:".uniqid(),
        score: $score,
        severity: $severity,
        validUntil: CarbonImmutable::now()->addHour(),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: $payload,
        createdAt: CarbonImmutable::now(),
    );
}

/** Record a past dismissal of an alert type (the demotion signal). */
function recordDismissal(User $user, string $type, string $key): void
{
    UserEvent::create([
        'user_id' => $user->id,
        'event_type' => 'card_dismissed',
        'session_id' => 'test',
        'payload' => ['action_key' => "{$type}:{$key}", 'card_type' => $type, 'source' => 'tile_dismiss'],
    ]);
}

test('a triaged tile drops out of the next build, and Undo brings it back', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(12)]);
    $userTask = overdueDeadline($user);
    $tileKey = "task:{$userTask->id}";

    expect(collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('key'))->toContain($tileKey);

    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'bureaucracy_deadline', 'key' => $tileKey, 'action' => 'dismiss'])
        ->assertOk();

    expect(collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('key'))->not->toContain($tileKey)
        ->and(UserEvent::where('user_id', $user->id)->where('event_type', 'card_dismissed')->count())->toBe(1);

    // Undo lifts the hide AND clears the dismiss signal (no lingering demotion).
    $this->actingAs($user)
        ->postJson('/api/tiles/triage/undo', ['type' => 'bureaucracy_deadline', 'key' => $tileKey])
        ->assertOk();

    expect(collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('key'))->toContain($tileKey)
        ->and(UserEvent::where('user_id', $user->id)->where('event_type', 'card_dismissed')->count())->toBe(0);
});

test('dismiss records a thumbs-down learning signal; snooze does not', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'weather_alert', 'key' => 'rain_17:00', 'action' => 'snooze'])
        ->assertOk();
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'card_dismissed')->count())->toBe(0);

    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'weather_alert', 'key' => 'rain_17:00', 'action' => 'dismiss'])
        ->assertOk();
    expect(UserEvent::where('user_id', $user->id)->where('event_type', 'card_dismissed')->where('payload->card_type', 'weather_alert')->count())->toBe(1);
});

test('snooze hides for a few hours; dismiss hides for the day', function () {
    $user = User::factory()->onboarded()->create();
    $triage = app(TileTriage::class);

    $triage->apply($user->id, 'weather_alert', 'snooze_key', 'snooze', 3 * 3600);
    $triage->apply($user->id, 'weather_alert', 'dismiss_key', 'dismiss', 24 * 3600);

    expect((int) Redis::ttl("tile_triage:{$user->id}:weather_alert:snooze_key"))->toBeGreaterThan(0)->toBeLessThanOrEqual(3 * 3600)
        ->and((int) Redis::ttl("tile_triage:{$user->id}:weather_alert:dismiss_key"))->toBeGreaterThan(12 * 3600);
});

test('dismissing a kind of alert demotes it in the ranking', function () {
    $user = User::factory()->onboarded()->create();
    $bus = app(ActionBus::class);

    // Two soft (warn) alerts; weather outranks rhine on raw score. A hazard-kind
    // weather alert always tiles (no plan/day fusion needed for this test).
    $bus->insert($user, triageBusAction('weather_alert', 60.0, 'major', ['alert' => ['title' => 'Heat warning', 'description' => 'x', 'kind' => 'hazard']]));
    $bus->insert($user, triageBusAction('rhine_level', 55.0, 'major', ['level' => 5, 'threshold' => 4]));

    $before = collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('type');
    expect($before->search('weather_alert'))->toBeLessThan($before->search('rhine_level'));

    // Two prior dismissals of weather_alert → a 40-point penalty sinks it below rhine.
    recordDismissal($user, 'weather_alert', 'old1');
    recordDismissal($user, 'weather_alert', 'old2');

    $after = collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('type');
    expect($after->search('rhine_level'))->toBeLessThan($after->search('weather_alert'));
});

test('a danger-severity alert is never demoted by past dismissals', function () {
    $user = User::factory()->onboarded()->create();
    $bus = app(ActionBus::class);

    // A CRITICAL weather alert (danger hazard) sitting just above a warn rhine alert.
    $bus->insert($user, triageBusAction('weather_alert', 60.0, 'critical', ['alert' => ['title' => 'Storm', 'description' => 'x', 'kind' => 'hazard']]));
    $bus->insert($user, triageBusAction('rhine_level', 55.0, 'major', ['level' => 5, 'threshold' => 4]));

    // Heavy prior dismissals that would otherwise bury weather alerts.
    foreach (['old1', 'old2', 'old3'] as $key) {
        recordDismissal($user, 'weather_alert', $key);
    }

    // The danger storm keeps its rank — safety alerts aren't demoted.
    $types = collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('type');
    expect($types->search('weather_alert'))->toBeLessThan($types->search('rhine_level'));
});

test('the triage endpoint rejects an unknown action', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'weather_alert', 'key' => 'x', 'action' => 'delete'])
        ->assertStatus(422);
});
