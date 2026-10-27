<?php

use App\Composer\PrivatePlanCache;
use App\Composer\WorkspaceUpgrade;
use App\Models\ComposerWorkspace;
use App\Models\User;

/** Two conversations: "Weekend" holds a change, an action record and a hand-off; "Visa" its own. */
function managedWorkspace(User $user, string $active = 'weekend'): void
{
    $at = now()->subHour()->toIso8601String();
    $date = now('Europe/Berlin')->addDay()->format('Y-m-d');
    $record = fn (string $token, string $domain): array => ['token' => $token, 'domain' => $domain, 'expires_at' => now()->addDay()->toIso8601String(), 'blocks' => []];
    $change = fn (string $id): array => ['id' => $id, 'date' => $date, 'label' => 'Planned', 'rows' => [], 'before' => null, 'after' => null, 'created_at' => $at, 'undone_at' => null];

    ComposerWorkspace::query()->create(['user_id' => $user->id, 'revision' => 3, 'state' => [
        'schema' => WorkspaceUpgrade::SCHEMA, 'active' => $active, 'focus' => null, 'plans' => [],
        'changes' => [$change('change-weekend'), $change('change-visa')],
        'actions' => ['tok-weekend' => $record('tok-weekend', 'paperwork'), 'tok-visa' => $record('tok-visa', 'day')],
        'conversations' => [
            ['id' => 'weekend', 'title' => 'Plan my weekend', 'created_at' => $at, 'messages' => [
                ['id' => 'w1', 'role' => 'user', 'text' => 'Plan my weekend', 'created_at' => $at],
                ['id' => 'w2', 'role' => 'assistant', 'text' => 'Added.', 'created_at' => $at, 'kind' => 'change', 'change_id' => 'change-weekend'],
                ['id' => 'w3', 'role' => 'assistant', 'text' => '', 'created_at' => $at, 'request' => 'ask',
                    'blocks' => [['id' => 'tok-weekend', 'type' => 'done', 'text' => 'Saved'], ['id' => 'plan1', 'type' => 'plan', 'token' => 'handoff-weekend']]],
            ]],
            ['id' => 'visa', 'title' => 'My visa', 'created_at' => $at, 'messages' => [
                ['id' => 'v1', 'role' => 'user', 'text' => 'My visa', 'created_at' => $at],
                ['id' => 'v2', 'role' => 'assistant', 'text' => 'Changed.', 'created_at' => $at, 'kind' => 'change', 'change_id' => 'change-visa',
                    'blocks' => [['id' => 'tok-visa', 'type' => 'done', 'text' => 'Saved']]],
            ]],
        ],
    ]]);
}

test('a conversation is renamed, and an empty title goes back to the automatic one', function () {
    $user = User::factory()->onboarded()->create();
    managedWorkspace($user);
    $this->actingAs($user);

    $this->patchJson('/composer/conversations/weekend', ['title' => '  Saturday   in Ehrenfeld '])->assertOk()
        ->assertJsonPath('conversations.0.title', 'Saturday in Ehrenfeld');
    $this->getJson('/composer/workspace')->assertJsonPath('conversations.0.title', 'Saturday in Ehrenfeld');

    $this->patchJson('/composer/conversations/weekend', ['title' => ''])->assertOk()
        ->assertJsonPath('conversations.0.title', 'Plan my weekend');
    $this->patchJson('/composer/conversations/weekend', [])->assertUnprocessable();
});

test('🔒 deleting a conversation removes its messages, records, Undo and hand-offs; the rest stays', function () {
    $user = User::factory()->onboarded()->create();
    managedWorkspace($user, active: 'visa');
    app(PrivatePlanCache::class)->putHandoff($user, 'handoff-weekend', ['date' => 'x', 'slots' => []]);

    $this->actingAs($user)->deleteJson('/composer/conversations/weekend')->assertOk()
        ->assertJsonCount(1, 'conversations')->assertJsonPath('conversations.0.id', 'visa')->assertJsonPath('active', 'visa');

    $state = ComposerWorkspace::query()->where('user_id', $user->id)->first()->state;
    expect(json_encode($state))->not->toContain('Plan my weekend')
        ->and(array_keys($state['actions']))->toBe(['tok-visa'])
        ->and(array_column($state['changes'], 'id'))->toBe(['change-visa'])
        ->and(app(PrivatePlanCache::class)->handoff($user, 'handoff-weekend'))->toBeNull();
});

test('🔒 someone else’s conversation is never renamed or deleted', function () {
    $owner = User::factory()->onboarded()->create();
    managedWorkspace($owner);
    $before = ComposerWorkspace::query()->where('user_id', $owner->id)->first()->state;
    $other = User::factory()->onboarded()->create();
    managedWorkspace($other);
    ComposerWorkspace::query()->where('user_id', $other->id)->update(['state' => [...ComposerWorkspace::query()->where('user_id', $other->id)->first()->state, 'conversations' => []]]);

    $this->actingAs($other)->deleteJson('/composer/conversations/weekend')->assertNotFound();
    $this->actingAs($other)->patchJson('/composer/conversations/visa', ['title' => 'Mine now'])->assertNotFound();
    expect(ComposerWorkspace::query()->where('user_id', $owner->id)->first()->state)->toBe($before);

    auth()->logout();
    $this->deleteJson('/composer/conversations/weekend')->assertUnauthorized();
});

test('deleting the open conversation opens an empty one, listed nowhere, without a new chat', function () {
    $user = User::factory()->onboarded()->create();
    managedWorkspace($user, active: 'weekend');

    $state = $this->actingAs($user)->deleteJson('/composer/conversations/weekend')->assertOk()->json();
    $open = collect($state['conversations'])->firstWhere('id', $state['active']);
    expect($open['messages'])->toBe([])
        ->and(collect($state['conversations'])->filter(fn (array $c): bool => $c['messages'] !== [])->pluck('id')->all())->toBe(['visa'])
        ->and($state['focus'])->toBeNull();

    // An empty one already there is opened again, not another made.
    $state = $this->deleteJson('/composer/conversations/visa')->assertOk()->json();
    expect($state['conversations'])->toHaveCount(1)->and($state['active'])->toBe($open['id']);
});
