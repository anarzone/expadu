<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\ReadModel\ProcessReassessmentOutbox;
use App\Bureaucracy\ReadModel\ReassessPerson;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use App\Notifications\BureaucracyPlanNotification;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Request as PushRequest;
use GuzzleHttp\Psr7\Response as PushResponse;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    Notification::fake();
    config()->set('context_engine.push_via_bus', true);
    $this->actor = User::factory()->onboarded()->create(['city' => 'Köln', 'situation' => null]);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'entry_mode', 'd_visa', null, 1);
    $this->expiry = app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'visa_expires_at', now()->addDays(2)->toDateString(), null, 2);
    $this->task = Task::factory()->approvedFixture()->create(['key' => 'fixture.reminder', 'title' => 'Synthetic private preparation',
        'type' => 'task', 'applies_if' => [['entry_mode' => 'd_visa']], 'depends_on' => [], 'deadline_type' => 'fact_date',
        'deadline_fact_key' => 'visa_expires_at', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$this->task], [$this->task->key => [
        'process_id' => 'fixture.reminder', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
});

test('scheduled reminders include skippable onboarding and use the exact current attention event', function () {
    $this->artisan('bureaucracy:remind', ['--user' => $this->actor->id])->assertSuccessful();
    $actions = app(ActionBus::class)->topK($this->actor->id);
    expect($actions)->toHaveCount(1);
    $resolved = app(PlanReminderReference::class)->resolve($actions[0]->payload, $this->actor->id);
    $attention = app(PlanAttention::class)->for(app(AccountHolderPlan::class)->for($this->actor));
    expect($resolved['event_revision'])->toBe($attention[0]['event_revision'])
        ->and($resolved['kind'])->toBe('preparation_target')->and($this->actor->userTasks()->count())->toBe(0)
        ->and(BureaucracyProcess::query()->count())->toBe(0);
    // Neither queued actions nor stored alerts contain the personal title or dates.
    expect(json_encode($actions[0]))->not->toContain('Synthetic private preparation', $this->expiry->value)
        ->and(json_encode($this->actor->alerts()->get()->toArray()))->not->toContain('Synthetic private preparation', $this->expiry->value);
    $notification = app(ContextNotificationFactory::class)->build($actions[0]);
    expect($notification)->toBeInstanceOf(BureaucracyPlanNotification::class)
        ->and(serialize($notification))->not->toContain('Synthetic private preparation', $this->expiry->value);
});

test('queued reminders recheck preferences and changed facts rather than delivering cached claims', function () {
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $action = app(ActionBus::class)->topK($this->actor->id)[0];
    $notification = app(ContextNotificationFactory::class)->build($action);
    expect($notification->shouldSend($this->actor, 'database'))->toBeTrue();
    $this->actor->load('notificationPreference');
    $this->actor->notificationPreference()->updateOrCreate([], ['preferences' => ['checklist' => false]]);
    expect($notification->shouldSend($this->actor, 'database'))->toBeFalse();
    $this->actor->notificationPreference()->update(['preferences' => ['checklist' => true]]);
    app(CorrectFact::class)->execute($this->actor, $this->case->person, $this->expiry->id, now()->addMonths(6)->toDateString(), 3);
    expect(app(ActionBus::class)->topK($this->actor->id))->toBeEmpty()
        ->and($notification->shouldSend($this->actor, 'database'))->toBeFalse();
});

test('source withdrawal and a different recipient invalidate reminder references', function () {
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $action = app(ActionBus::class)->topK($this->actor->id)[0];
    $references = app(PlanReminderReference::class);
    expect($references->resolve($action->payload, User::factory()->create()->id))->toBeNull();
    $this->task->update(['is_published' => false]);
    expect($references->resolve($action->payload, $this->actor->id))->toBeNull()
        ->and(app(ContextNotificationFactory::class)->build($action))->toBeNull();
});

test('completed delivery is deduplicated but a suppressed reservation can be retried', function () {
    $evaluator = app(BureaucracyEvaluator::class);
    $evaluator->evaluate($this->actor);
    $action = app(ActionBus::class)->topK($this->actor->id)[0];
    $delivery = app(PlanReminderDelivery::class);
    $delivery->release($action->payload);
    expect($delivery->isReserved($action->payload))->toBeFalse();
    $evaluator->evaluate($this->actor);
    $retry = app(ActionBus::class)->topK($this->actor->id)[0];
    expect($delivery->isReserved($retry->payload))->toBeTrue();
    $delivery->delivered($retry->payload);
    $evaluator->evaluate($this->actor);
    $last = app(ActionBus::class)->topK($this->actor->id)[0];
    expect($last->deliverChannels)->not->toContain('push');
});

test('dry run does not allocate processes actions or notifications', function () {
    $this->artisan('bureaucracy:remind', ['--user' => $this->actor->id, '--dry-run' => true])->assertSuccessful();
    expect(app(ActionBus::class)->topK($this->actor->id))->toBeEmpty()->and(BureaucracyProcess::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a rolled back refresh never reserves or queues a reminder and can be retried', function () {
    config()->set('queue.default', 'database');
    Notification::swap(new ChannelManager(app()));
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'facts.changed')->latest('id')->firstOrFail();
    $failNextBoundary = true;
    $queuedBeforeFailure = null;
    Event::listen('eloquent.creating: '.BureaucracyOutboxEvent::class, function ($row) use (&$failNextBoundary, &$queuedBeforeFailure) {
        if ($row->event_type === 'person.reassessment_requested' && $failNextBoundary) {
            $failNextBoundary = false;
            $queuedBeforeFailure = DB::table('jobs')->count();
            throw new RuntimeException('Synthetic failure while recording the next assessment.');
        }
    });

    $worker = app(ProcessReassessmentOutbox::class);
    expect($worker->process($event))->toBeFalse()
        ->and($queuedBeforeFailure)->toBe(0)->and(DB::table('jobs')->count())->toBe(0)
        ->and($event->fresh()->delivered_at)->toBeNull();
    $this->travelTo($event->fresh()->available_at);
    expect($worker->process($event))->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and($event->fresh()->attempts)->toBe(2);
});

test('a refresh waits for its outer transaction before creating delivery side effects', function () {
    config()->set('queue.default', 'database');
    Notification::swap(new ChannelManager(app()));
    $queuedBeforeRollback = null;
    try {
        DB::transaction(function () use (&$queuedBeforeRollback) {
            app(ReassessPerson::class)->execute($this->case->person_id);
            $queuedBeforeRollback = DB::table('jobs')->count();
            throw new RuntimeException('Synthetic outer rollback.');
        });
    } catch (RuntimeException) {
    }
    expect($queuedBeforeRollback)->toBe(0)->and(DB::table('jobs')->count())->toBe(0)
        ->and(app(ActionBus::class)->topK($this->actor->id))->toBeEmpty();
    app(ReassessPerson::class)->execute($this->case->person_id);
    expect(DB::table('jobs')->count())->toBe(1);
});

test('a queue admission failure leaves the refresh retryable without storing private error text', function (bool $insideTransaction) {
    config()->set('queue.default', 'database');
    $manager = Mockery::mock(ChannelManager::class, [app()])->makePartial();
    $manager->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic private queue failure.'));
    Notification::swap($manager);
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'facts.changed')->latest('id')->firstOrFail();
    $worker = app(ProcessReassessmentOutbox::class);
    $processed = $insideTransaction ? DB::transaction(fn () => $worker->process($event)) : $worker->process($event);
    expect($processed)->toBeFalse()
        ->and($event->fresh()->delivered_at)->toBeNull()
        ->and($event->fresh()->last_error_type)->toBe(RuntimeException::class)
        ->and(json_encode($event->fresh()->getAttributes()))->not->toContain('Synthetic private queue failure.');
    $this->travelTo($event->fresh()->available_at);
    Notification::swap(new ChannelManager(app()));
    expect($worker->process($event))->toBeTrue()->and(DB::table('jobs')->count())->toBe(1);
})->with([false, true]);

test('a nested refresh is acknowledged only after the committed claim is processed', function () {
    config()->set('queue.default', 'database');
    Notification::swap(new ChannelManager(app()));
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'facts.changed')->latest('id')->firstOrFail();
    DB::transaction(function () use ($event) {
        expect(app(ProcessReassessmentOutbox::class)->process($event))->toBeFalse()
            ->and($event->fresh()->delivered_at)->toBeNull()
            ->and(DB::table('jobs')->count())->toBe(0);
    });
    expect($event->fresh()->delivered_at)->not->toBeNull()->and(DB::table('jobs')->count())->toBe(1);
});

test('a stale reminder rejected before queueing releases its own reservation for fresh guidance', function () {
    $this->travelTo('2026-09-08 23:59:30');
    $plan = app(AccountHolderPlan::class)->for($this->actor);
    $row = app(PlanAttention::class)->for($plan)[0];
    $references = app(PlanReminderReference::class);
    $delivery = app(PlanReminderDelivery::class);
    $reference = $references->for($this->actor, $row);
    $reference['reservation'] = $delivery->reserve($reference);
    $action = new ScoredAction('bureaucracy_task', 'fixture.stale-reminder', 50, 'moderate',
        CarbonImmutable::parse($plan['next_reassessment_at']), ['push'], $reference, CarbonImmutable::now());
    // Cross a calendar boundary before dispatch, but not the one-hour reservation expiry.
    $this->travel(1)->minutes();
    $current = $references->for($this->actor, app(PlanAttention::class)->for(app(AccountHolderPlan::class)->for($this->actor))[0]);
    expect($references->resolve($reference, $this->actor->id))->toBeNull()
        ->and($references->deliveryKey($reference))->toBe($references->deliveryKey($current));
    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($this->actor, $action));
    Notification::assertNothingSent();
    expect($delivery->isReserved($reference))->toBeFalse()->and($delivery->reserve($current))->not->toBeNull();
});

test('a delayed but still current queued reminder can safely reclaim an expired reservation', function () {
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $action = app(ActionBus::class)->topK($this->actor->id)[0];
    $notification = app(ContextNotificationFactory::class)->build($action);
    $this->travel(61)->minutes();
    expect(app(PlanReminderReference::class)->resolve($action->payload, $this->actor->id))->not->toBeNull()
        ->and($notification->shouldSend($this->actor, WebPushChannel::class))->toBeTrue();
});

test('mute identifiers are opaque recipient-scoped tokens rather than hashes of a known document name', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'residence_card_expires_at', now()->addDay()->toDateString(), null, 3);
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = app(ActionBus::class)->topK($this->actor->id);
    $action = collect($actions)->first(fn ($row) => app(PlanReminderReference::class)->resolve($row->payload)['kind'] === 'document_expiry');
    expect($action->payload['mute_key'])->not->toBe(hash('sha256', 'residence-card.expiry'));
    $this->actingAs($this->actor)->postJson('/mutes', ['type' => 'bureaucracy_task', 'key' => $action->payload['mute_key']])->assertSuccessful();
    expect(app(ContextNotificationFactory::class)->build($action)->shouldSend($this->actor, WebPushChannel::class))->toBeFalse();
});

test('actual WebPush reports determine delivery once even with multiple subscriptions', function (string $mode) {
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $action = app(ActionBus::class)->topK($this->actor->id)[0];
    $notification = app(ContextNotificationFactory::class)->build($action);
    $reports = [];
    if ($mode !== 'no_subscriptions') {
        foreach (['first', 'second'] as $suffix) {
            $endpoint = 'https://push.example.test/'.$suffix;
            $this->actor->pushSubscriptions()->create(['endpoint' => $endpoint]);
            $reports[] = new MessageSentReport(new PushRequest('POST', $endpoint), new PushResponse($mode === 'success' ? 201 : 503), $mode === 'success');
        }
    }
    $transport = Mockery::mock(WebPush::class);
    if ($reports !== []) {
        $transport->shouldReceive('queueNotification')->twice()->withArgs(function ($subscription, $payload, $options) {
            expect($payload)->not->toContain('sealed', 'recipient_id', 'reminderReference', 'Synthetic private preparation');

            return true;
        });
        $transport->shouldReceive('flush')->once()->andReturn((function () use ($reports) {
            yield from $reports;
        })());
    }
    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn () => $transport);
    Notification::swap(new ChannelManager(app()));
    Notification::sendNow($this->actor, $notification);
    $delivery = app(PlanReminderDelivery::class);
    $hour = 'notif_throttle:hour:'.$this->actor->id.':'.now()->format('Y-m-d-H');
    expect((int) Redis::get($hour))->toBe($mode === 'success' ? 1 : 0);
    if ($mode === 'success') {
        expect($delivery->reserve($action->payload))->toBeNull();
    } else {
        expect($delivery->reserve($action->payload))->not->toBeNull();
    }
})->with(['no_subscriptions', 'failed', 'success']);
