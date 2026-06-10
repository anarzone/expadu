<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Home\TileComposer;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'pending_actions:*'
    );
});

function busAction(string $type, float $score, array $payload, array $channels = [ScoredAction::CHANNEL_DASHBOARD]): ScoredAction
{
    return new ScoredAction(
        type: $type,
        actionKey: "{$type}:".uniqid(),
        score: $score,
        severity: 'major',
        validUntil: CarbonImmutable::now()->addHour(),
        deliverChannels: $channels,
        payload: $payload,
        createdAt: CarbonImmutable::now(),
    );
}

test('bus actions become tiles ranked with synthetic tiles by score', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(12)]);

    // Overdue deadline → synthetic tile at score 95
    $task = Task::factory()->create([
        'title' => 'Register your address (Anmeldung)',
        'situation' => [$user->situation->value],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 5,
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id, 'is_applicable' => true]);

    // Disruption from the bus at score 56
    app(ActionBus::class)->insert($user, busAction('transit_disruption', 56.0, [
        'disruption_id' => 1,
        'lines' => ['12'],
        'stops_affected' => [],
    ]));

    $tiles = app(TileComposer::class)->tiles($user);

    expect($tiles)->not->toBeEmpty();

    $types = collect($tiles)->pluck('type');
    expect($types)->toContain('bureaucracy_deadline');
    expect($types)->toContain('transit_disruption');

    // Overdue deadline (95) must outrank the disruption (56)
    $deadlineIndex = $types->search('bureaucracy_deadline');
    $disruptionIndex = $types->search('transit_disruption');
    expect($deadlineIndex)->toBeLessThan($disruptionIndex);
});

test('bus bureaucracy_task actions are skipped in favour of live deadline tiles', function () {
    $user = User::factory()->onboarded()->create();

    app(ActionBus::class)->insert($user, busAction('bureaucracy_task', 80.0, [
        'title' => 'Stale snapshot task',
        'tier' => 'critical',
        'days_remaining' => 1,
        'deadline' => now()->toDateString(),
    ]));

    $tiles = app(TileComposer::class)->tiles($user);

    expect(collect($tiles)->pluck('title'))->not->toContain('Stale snapshot task');
});

test('tile list is capped at eight', function () {
    $user = User::factory()->onboarded()->create();
    $bus = app(ActionBus::class);

    foreach (range(1, 12) as $i) {
        $bus->insert($user, busAction('transit_delay', 40 + $i, [
            'line' => (string) $i,
            'delay_min' => 15,
            'direction' => 'Test',
            'stop_id' => 'S1',
        ]));
    }

    expect(app(TileComposer::class)->tiles($user))->toHaveCount(8);
});

test('dashboard-only filtering respects deliver channels', function () {
    $user = User::factory()->onboarded()->create();

    app(ActionBus::class)->insert($user, busAction('transit_disruption', 60.0, [
        'disruption_id' => 2,
        'lines' => ['7'],
        'stops_affected' => [],
    ], [ScoredAction::CHANNEL_ALERT_PAGE]));

    $tiles = app(TileComposer::class)->tiles($user);

    expect(collect($tiles)->pluck('type'))->not->toContain('transit_disruption');
});
