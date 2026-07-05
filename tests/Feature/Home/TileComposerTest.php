<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Home\TileComposer;
use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
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

    $tiles = app(TileComposer::class)->tiles(homeContext($user));

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

    $tiles = app(TileComposer::class)->tiles(homeContext($user));

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

    expect(app(TileComposer::class)->tiles(homeContext($user)))->toHaveCount(8);
});

test('dashboard-only filtering respects deliver channels', function () {
    $user = User::factory()->onboarded()->create();

    app(ActionBus::class)->insert($user, busAction('transit_disruption', 60.0, [
        'disruption_id' => 2,
        'lines' => ['7'],
        'stops_affected' => [],
    ], [ScoredAction::CHANNEL_ALERT_PAGE]));

    $tiles = app(TileComposer::class)->tiles(homeContext($user));

    expect(collect($tiles)->pluck('type'))->not->toContain('transit_disruption');
});

test('a legal deadline is immune to dismissal demotion even when it is not danger-severity', function () {
    // An "urgent" deadline (4–7 days out) tiles at score 65 with INFO severity —
    // not danger. Under the old danger-only rule, three dismissals would demote
    // 'bureaucracy_deadline' by 60 and sink it below a lesser tile. The floor
    // rule keys on consequence, so it must stay on top: the app must never learn
    // to bury a legal deadline just because the user cleared it before.
    $user = User::factory()->onboarded()->create(['arrival_date' => now()]);

    $task = Task::factory()->create([
        'title' => 'Register your address (Anmeldung)',
        'situation' => [$user->situation->value],
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 6, // → 6 days left → 'urgent' → score 65, severity info
    ]);
    UserTask::create(['user_id' => $user->id, 'task_id' => $task->id, 'is_applicable' => true]);

    // A competing tile that would win once the deadline is demoted (65 − 60 = 5).
    app(ActionBus::class)->insert($user, busAction('transit_disruption', 60.0, [
        'disruption_id' => 9,
        'lines' => ['1'],
        'stops_affected' => [],
    ]));

    // Three prior dismissals of the deadline type — a full demotion under the cap.
    foreach (range(1, 3) as $i) {
        UserEvent::create([
            'user_id' => $user->id,
            'event_type' => 'card_dismissed',
            'session_id' => 'test',
            'payload' => ['card_type' => 'bureaucracy_deadline', 'source' => 'tile_dismiss'],
        ]);
    }

    $types = collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('type');

    // Deadline still outranks the disruption despite three dismissals.
    expect($types->search('bureaucracy_deadline'))
        ->toBeLessThan($types->search('transit_disruption'));
});

test('no generic weekend composer shortcut tile, even inside the weekend window', function () {
    // "Right now" is for time-sensitive items only. The prompt box and the
    // "Plan my weekend" chip already cover planning — a duplicate tile here
    // was removed. Travel to a Sunday afternoon (a weekend window).
    $this->travelTo(CarbonImmutable::parse('2026-06-14 14:00', 'Europe/Berlin'));

    $user = User::factory()->onboarded()->create();

    $tiles = app(TileComposer::class)->tiles(homeContext($user));

    expect(collect($tiles)->pluck('type'))->not->toContain('composer_shortcut');
    expect(collect($tiles)->pluck('title'))->not->toContain('Plan your weekend');
});
