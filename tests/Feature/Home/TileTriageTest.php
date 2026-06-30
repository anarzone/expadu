<?php

use App\Home\TileComposer;
use App\Home\TileTriage;
use App\Models\Task;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserTask;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // Triage keys live in raw Redis with long TTLs, so clear them between tests
    // (the suite reuses climbing user ids; a leak would hide an unrelated tile).
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'tile_triage:*'
    );
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

test('a triaged tile drops out of the next feed build, and Restore brings it back', function () {
    $user = User::factory()->onboarded()->create(['arrival_date' => now()->subDays(12)]);
    $userTask = overdueDeadline($user);
    $tileKey = "task:{$userTask->id}";

    // It shows before triage.
    $before = collect(app(TileComposer::class)->tiles(homeContext($user)))->pluck('key');
    expect($before)->toContain($tileKey);

    // Dismiss it through the endpoint.
    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'bureaucracy_deadline', 'key' => $tileKey, 'action' => 'dismiss'])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Gone on the next build — not just the current render — and counted as
    // suppressed so "Restore all" can resurface after a reload.
    $composer = app(TileComposer::class);
    $after = collect($composer->tiles(homeContext($user)))->pluck('key');
    expect($after)->not->toContain($tileKey)
        ->and($composer->suppressedCount())->toBe(1);

    // Restore all → it returns and the suppressed count clears.
    $this->actingAs($user)->postJson('/api/tiles/triage/clear')->assertOk();
    $restoredComposer = app(TileComposer::class);
    $restored = collect($restoredComposer->tiles(homeContext($user)))->pluck('key');
    expect($restored)->toContain($tileKey)
        ->and($restoredComposer->suppressedCount())->toBe(0);
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

    $snoozeTtl = (int) Redis::ttl("tile_triage:{$user->id}:weather_alert:snooze_key");
    $dismissTtl = (int) Redis::ttl("tile_triage:{$user->id}:weather_alert:dismiss_key");

    expect($snoozeTtl)->toBeGreaterThan(0)->toBeLessThanOrEqual(3 * 3600)
        ->and($dismissTtl)->toBeGreaterThan(12 * 3600)
        ->and($triage->isActive($user->id, 'weather_alert', 'snooze_key'))->toBeTrue();
});

test('the triage endpoint rejects an unknown action', function () {
    $user = User::factory()->onboarded()->create();

    $this->actingAs($user)
        ->postJson('/api/tiles/triage', ['type' => 'weather_alert', 'key' => 'x', 'action' => 'delete'])
        ->assertStatus(422);
});
