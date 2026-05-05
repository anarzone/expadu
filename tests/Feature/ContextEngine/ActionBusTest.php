<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\ScoredAction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

afterEach(function () {
    foreach (User::pluck('id') as $id) {
        Redis::del("pending_actions:{$id}");
    }
});

test('insert + topK returns actions in score order', function () {
    $user = User::factory()->create();
    $bus = app(ActionBus::class);

    $bus->insert($user, makeAction('low', 10.0));
    $bus->insert($user, makeAction('high', 90.0));
    $bus->insert($user, makeAction('mid', 50.0));

    $actions = $bus->topK($user->id, 3);
    expect(collect($actions)->pluck('actionKey')->all())->toEqual(['high', 'mid', 'low']);
});

test('reinsert with same action_key replaces, does not duplicate', function () {
    $user = User::factory()->create();
    $bus = app(ActionBus::class);

    $bus->insert($user, makeAction('same', 10.0));
    $bus->insert($user, makeAction('same', 90.0));

    $actions = $bus->topK($user->id, 5);
    expect($actions)->toHaveCount(1);
    expect($actions[0]->score)->toBe(90.0);
});

test('expired members are swept on read', function () {
    $user = User::factory()->create();
    $bus = app(ActionBus::class);

    $bus->insert($user, new ScoredAction(
        type: 'transit_disruption',
        actionKey: 'gone',
        score: 50.0,
        severity: 'major',
        validUntil: CarbonImmutable::now()->subMinute(),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: [],
        createdAt: CarbonImmutable::now(),
    ));

    expect($bus->topK($user->id, 5))->toBeEmpty();
});

function makeAction(string $key, float $score): ScoredAction
{
    return new ScoredAction(
        type: 'transit_disruption',
        actionKey: $key,
        score: $score,
        severity: 'major',
        validUntil: CarbonImmutable::now()->addHour(),
        deliverChannels: [ScoredAction::CHANNEL_DASHBOARD],
        payload: [],
        createdAt: CarbonImmutable::now(),
    );
}
