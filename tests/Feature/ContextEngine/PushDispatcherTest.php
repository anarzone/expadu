<?php

use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Models\User;
use App\Notifications\BureaucracyDeadlineNotification;
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

test('bureaucracy_task actions build a deadline notification', function () {
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

    Notification::assertSentTo($user, BureaucracyDeadlineNotification::class, function ($notification) {
        return $notification->taskTitle === 'Register your address (Anmeldung)'
            && $notification->tier === 'critical'
            && $notification->daysRemaining === 2;
    });
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
