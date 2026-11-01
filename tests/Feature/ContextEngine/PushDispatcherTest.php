<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Models\Task;
use App\Models\User;
use App\Notifications\BureaucracyPlanNotification;
use App\Notifications\TransitDelayNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

uses()->group('context-engine');

function makePushAction(string $type, array $payload, array $channels = [ScoredAction::CHANNEL_PUSH]): ScoredAction
{
    return new ScoredAction(
        type: $type,
        actionKey: "{$type}:test",
        score: 50.0,
        severity: 'major',
        validUntil: CarbonImmutable::now()->addHour(),
        deliverChannels: $channels,
        payload: $payload,
        createdAt: CarbonImmutable::now(),
    );
}

test('push action sends exactly one notification when push_via_bus is on', function () {
    config(['context_engine.push_via_bus' => true]);
    Notification::fake();

    $user = User::factory()->create();
    $action = makePushAction('transit_delay', [
        'line' => '18',
        'delay_min' => 15,
        'stop_id' => 'Neumarkt',
    ]);

    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($user, $action));

    Notification::assertSentToTimes($user, TransitDelayNotification::class, 1);
});

test('legacy plaintext bureaucracy actions cannot send a deadline notification', function () {
    config(['context_engine.push_via_bus' => true]);
    Notification::fake();

    $user = User::factory()->create();
    $action = makePushAction('bureaucracy_task', [
        'user_task_id' => 1,
        'task_id' => 1,
        'title' => 'Register your address (Anmeldung)',
        'status' => 'not_started',
        'days_remaining' => 2,
        'tier' => 'critical',
        'deadline' => now()->addDays(2)->toDateString(),
        'booking_service_key' => 'anmeldung',
    ]);

    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($user, $action));

    Notification::assertNothingSent();
});

test('a current sealed bureaucracy reference dispatches without embedding personal guidance', function () {
    $this->travelTo('2026-09-08 10:00:00');
    config(['context_engine.push_via_bus' => true]);
    Notification::fake();
    $user = User::factory()->onboarded()->create(['city' => 'Köln']);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'entry_mode', 'd_visa', null, 1);
    app(RecordFactChange::class)->execute($user, $case->person, 'visa_expires_at', '2026-09-10', null, 2);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.push', 'title' => 'Synthetic private preparation', 'type' => 'task',
        'applies_if' => [['entry_mode' => 'd_visa']], 'depends_on' => [],
        'deadline_type' => 'fact_date', 'deadline_fact_key' => 'visa_expires_at',
        'documents_required' => [], 'how_to_steps' => [], 'links' => [],
    ])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => 'fixture.push', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]));
    $store->activate($release->id, null);
    $row = app(PlanAttention::class)->for(app(AccountHolderPlan::class)->for($user))[0];
    $reference = app(PlanReminderReference::class)->for($user, $row);
    $reference['reservation'] = app(PlanReminderDelivery::class)->reserve($reference);
    expect($reference['reservation'])->toBeString();
    $action = makePushAction('bureaucracy_task', $reference);

    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($user, $action));

    Notification::assertSentToTimes($user, BureaucracyPlanNotification::class, 1);
    Notification::assertSentTo($user, BureaucracyPlanNotification::class, fn ($notification) => $notification->shouldSend($user, 'database')
        && ! str_contains(serialize($notification), $task->title)
        && ! str_contains(serialize($notification), '2026-09-10'));
});

test('dashboard-only actions never notify', function () {
    config(['context_engine.push_via_bus' => true]);
    Notification::fake();

    $user = User::factory()->create();
    $action = makePushAction('transit_delay', [
        'line' => '18',
        'delay_min' => 15,
        'stop_id' => 'Neumarkt',
    ], [ScoredAction::CHANNEL_DASHBOARD]);

    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($user, $action));

    Notification::assertNothingSent();
});

test('kill switch (push_via_bus=false) logs instead of sending', function () {
    config(['context_engine.push_via_bus' => false]);
    Notification::fake();

    $user = User::factory()->create();
    $action = makePushAction('transit_delay', [
        'line' => '18',
        'delay_min' => 15,
        'stop_id' => 'Neumarkt',
    ]);

    app(ScoredActionPushDispatcher::class)->handle(new ScoredActionInserted($user, $action));

    Notification::assertNothingSent();
});
