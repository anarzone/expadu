<?php

use App\Bureaucracy\Facts\CaseFactStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\Privacy\ProcessErasureOutbox;
use App\Bureaucracy\Privacy\PurgePersonCopies;
use App\Models\BureaucracyOutboxEvent;
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
