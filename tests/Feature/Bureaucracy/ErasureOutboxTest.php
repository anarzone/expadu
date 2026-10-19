<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\Privacy\ProcessErasureOutbox;
use App\Bureaucracy\Privacy\PurgePersonCopies;
use App\Composer\PrivatePlanCache;
use App\Models\BureaucracyOutboxEvent;
use App\Models\ComposerWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

test('erasure records a durable retry without keeping removed values and is idempotent', function () {
    $actor = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($actor, ['entry_mode' => 'd_visa'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($actor);
    app(PersonDataLifecycle::class)->erase($actor, $person);
    $event = BureaucracyOutboxEvent::query()->sole();
    expect($event->event_type)->toBe('person.erased')
        ->and(json_encode($event->getAttributes()))->not->toContain('d_visa');
    app(PersonDataLifecycle::class)->erase($actor, $person);
    expect(BureaucracyOutboxEvent::query()->count())->toBe(1);
    $purger = Mockery::mock(PurgePersonCopies::class);
    $purger->shouldReceive('purge')->once()->andThrow(new RuntimeException('Do not retain private error text'));
    $worker = new ProcessErasureOutbox($purger);
    expect($worker->process($event))->toBeFalse()
        ->and($event->fresh()->delivered_at)->toBeNull()
        ->and($event->fresh()->last_error_type)->toBe(RuntimeException::class)
        ->and(json_encode($event->fresh()->getAttributes()))->not->toContain('private error text');
    $this->travelTo($event->fresh()->available_at);
    $success = Mockery::mock(PurgePersonCopies::class);
    $success->shouldReceive('purge')->once()->andReturnNull();
    expect((new ProcessErasureOutbox($success))->process($event))->toBeTrue()
        ->and($event->fresh()->delivered_at)->not->toBeNull()
        ->and((new ProcessErasureOutbox($success))->process($event))->toBeFalse();
});

test('rolled back erasure never emits a cleanup event', function () {
    $actor = User::factory()->onboarded()->create();
    app(CaseFactStore::class)->synchronizeConfirmedFacts($actor, ['entry_mode' => 'd_visa'], 'onboarding');
    $person = app(EnsureAccountHolder::class)->person($actor);
    try {
        DB::transaction(function () use ($actor, $person) {
            app(PersonDataLifecycle::class)->erase($actor, $person);
            throw new RuntimeException('synthetic rollback');
        });
    } catch (RuntimeException) {
    }
    expect($person->fresh()->record_status)->toBe('active')->and(BureaucracyOutboxEvent::query()->count())->toBe(0);
});

test('derived cleanup removes bureaucracy copies but preserves unrelated cached actions and plans', function () {
    $actor = User::factory()->onboarded()->create();
    $busKey = 'pending_actions:'.$actor->id;
    $legal = json_encode(['type' => 'bureaucracy_task', 'payload' => ['title' => 'Synthetic private residence step']]);
    $weather = json_encode(['type' => 'weather_alert', 'payload' => ['title' => 'Rain']]);
    Redis::zadd($busKey, 10, $legal, 8, $weather);
    Cache::put('composer:plan:'.$actor->id, ['slots' => [['is_appointment' => true, 'name' => 'Synthetic office visit']], 'prompt' => 'Synthetic private prompt'], 100);
    Cache::put('composer:today:'.$actor->id, ['slots' => [['type' => 'place', 'name' => 'Park']]], 100);
    app(PurgePersonCopies::class)->purge(['subject_user_id' => $actor->id]);
    expect(Redis::zrange($busKey, 0, -1))->toBe([$weather])
        ->and(Cache::get('composer:plan:'.$actor->id))->toBeNull()
        ->and(Cache::get('composer:today:'.$actor->id)['slots'][0]['name'])->toBe('Park');
});

test('derived cleanup strips paperwork appointments from the stored Composer workspace and handoffs', function () {
    $actor = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $appointment = ['id' => 'appointment:x', 'type' => 'appointment', 'is_appointment' => true, 'name' => 'Synthetic office visit'];
    $personal = ['id' => 'personal:y', 'type' => 'personal', 'name' => 'Dentist'];
    $plan = ['date' => '2026-10-20', 'slots' => [$appointment, $personal]];
    $state = ['approval' => 'ask', 'active' => 'c', 'focus' => null, 'draft' => $plan, 'plans' => ['2026-10-20' => $plan],
        'versions' => [['id' => 'v', 'date' => '2026-10-20', 'before' => null, 'after' => $plan, 'undone' => false]],
        'conversations' => [['id' => 'c', 'title' => 'x', 'created_at' => now()->toIso8601String(), 'messages' => [['id' => 'm', 'role' => 'assistant', 'text' => '', 'kind' => 'plan', 'plan' => $plan]]]]];
    ComposerWorkspace::query()->create(['user_id' => $actor->id, 'state' => $state, 'revision' => 3]);
    ComposerWorkspace::query()->create(['user_id' => $other->id, 'state' => $state, 'revision' => 3]);
    app(PrivatePlanCache::class)->putHandoff($actor, 'token-a', $plan);

    app(PurgePersonCopies::class)->purge(['subject_user_id' => $actor->id]);

    $stored = json_encode(ComposerWorkspace::query()->where('user_id', $actor->id)->sole()->state);
    expect($stored)->not->toContain('Synthetic office visit')->toContain('Dentist')
        ->and(ComposerWorkspace::query()->where('user_id', $actor->id)->sole()->revision)->toBe(4)
        ->and(app(PrivatePlanCache::class)->handoff($actor, 'token-a'))->toBeNull()
        // Another account's workspace is not touched.
        ->and(json_encode(ComposerWorkspace::query()->where('user_id', $other->id)->sole()->state))->toContain('Synthetic office visit');
});

test('composer handoffs are encrypted at rest and readable only by their owner', function () {
    $owner = User::factory()->onboarded()->create();
    $plan = ['date' => '2026-10-20', 'slots' => [['id' => 'appointment:x', 'type' => 'appointment', 'name' => 'Synthetic office visit']]];
    app(PrivatePlanCache::class)->putHandoff($owner, 'token-a', $plan);

    expect((string) Cache::get('composer:handoff:'.$owner->id))->not->toContain('Synthetic office visit')
        ->and(app(PrivatePlanCache::class)->handoff($owner, 'token-a'))->toBe($plan)
        ->and(app(PrivatePlanCache::class)->handoff(User::factory()->onboarded()->create(), 'token-a'))->toBeNull();

    app(PrivatePlanCache::class)->forgetHandoff($owner, 'token-a');
    expect(app(PrivatePlanCache::class)->handoff($owner, 'token-a'))->toBeNull();
});
