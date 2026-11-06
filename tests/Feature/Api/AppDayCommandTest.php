<?php

use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\User;

/* The iPhone changes the day as the web's Today does: only its own, only Today's commands. */

function dayWith(User $user, string $date): void
{
    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 1, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => 'initial', 'focus' => null, 'changes' => [], 'conversations' => [],
        'plans' => [$date => ['date' => $date, 'constraints' => ['window_start' => "{$date}T08:00:00", 'window_end' => "{$date}T23:59:00"], 'slots' => [[
            'id' => 'manual:dinner', 'type' => 'manual', 'name' => 'Dinner', 'location' => 'Stadtgarten', 'category' => 'meetup',
            'date' => $date, 'start_at' => "{$date}T20:00:00+01:00", 'end_at' => "{$date}T22:00:00+01:00", 'start_time' => '20:00', 'end_time' => '22:00',
        ]]]],
    ]]);
}

test('🔒 changing the day and saving an event need a token and a confirmed address', function () {
    $this->postJson('/api/v1/day/command', ['revision' => 1, 'action' => 'remove', 'id' => 'x'])->assertUnauthorized();
    $this->postJson('/api/v1/events/saved', ['event_id' => 1, 'occurrence_start' => '2026-11-06T20:00:00+01:00', 'saved' => true])->assertUnauthorized();

    $token = User::factory()->unverified()->create()->createToken('iPhone')->plainTextToken;
    $this->withToken($token)->postJson('/api/v1/day/command', ['revision' => 1, 'action' => 'remove', 'id' => 'x'])->assertForbidden();
});

test('🔒 only Today’s own commands go through, not Composer’s', function () {
    $user = User::factory()->create();
    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/day/command', ['revision' => 0, 'action' => 'message', 'text' => 'plan my day'])
        ->assertUnprocessable()->assertJsonValidationErrors('action');
});

test('a plan is marked done, then removed with an Undo, each on the latest revision', function () {
    $user = User::factory()->create();
    $date = now('Europe/Berlin')->format('Y-m-d');
    dayWith($user, $date);
    $as = $this->withToken($user->createToken('iPhone')->plainTextToken);

    $as->postJson('/api/v1/day/command', ['revision' => 1, 'action' => 'complete', 'id' => 'manual:dinner', 'date' => $date])
        ->assertOk()->assertJsonPath('revision', 2);
    expect(ComposerWorkspace::query()->where('user_id', $user->id)->first()->state['plans'][$date]['slots'][0])->toHaveKey('completed_at');

    $as->postJson('/api/v1/day/command', ['revision' => 2, 'action' => 'remove', 'id' => 'manual:dinner', 'date' => $date])
        ->assertOk()->assertJsonPath('revision', 3)->assertJsonPath('change.label', 'Removed Dinner');
    expect(ComposerWorkspace::query()->where('user_id', $user->id)->first()->state['plans'][$date]['slots'] ?? [])->toBe([]);
});

test('a change on an old revision is refused and changes nothing', function () {
    $user = User::factory()->create();
    $date = now('Europe/Berlin')->format('Y-m-d');
    dayWith($user, $date);

    $this->withToken($user->createToken('iPhone')->plainTextToken)
        ->postJson('/api/v1/day/command', ['revision' => 0, 'action' => 'remove', 'id' => 'manual:dinner', 'date' => $date])
        ->assertConflict();
    expect(ComposerWorkspace::query()->where('user_id', $user->id)->first()->state['plans'][$date]['slots'])->toHaveCount(1);
});
