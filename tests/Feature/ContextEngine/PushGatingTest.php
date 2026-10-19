<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\ContextEngine\Listeners\ScoredActionPushDispatcher;
use App\ContextEngine\PushLedger;
use App\ContextEngine\ScoredAction;
use App\Events\Context\ScoredActionInserted;
use App\Events\Context\TransitDisruptionDetected;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Notifications\BureaucracyDeadlineNotification;
use App\Notifications\TransitDelayNotification;
use App\Services\DisruptionService;
use App\Support\NotificationThrottle;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use NotificationChannels\WebPush\WebPushChannel;

uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
    config(['context_engine.push_via_bus' => true]);
});

/** An onboarded user with a task five days overdue. */
function overdueUserTask(): UserTask
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
    ]);
    $task = Task::factory()->create([
        'key' => 'x.push_gating_'.uniqid(),
        'title' => 'Register your address',
        'is_published' => true,
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 5,
    ]);

    return UserTask::create(['user_id' => $user->id, 'task_id' => $task->id])->fresh();
}

/** @return list<string> */
function bureaucracyChannels(UserTask $userTask): array
{
    return collect(app(ActionBus::class)->topK($userTask->user_id, 20))
        ->firstWhere('type', 'bureaucracy_task')?->deliverChannels ?? [];
}

function holdPushThrottle(int $userId): void
{
    Redis::set("notif_throttle:last:{$userId}", now()->timestamp);
}

function releasePushThrottle(int $userId): void
{
    Redis::del("notif_throttle:last:{$userId}");
}

test('a critical disruption may push in quiet hours; a major one may not', function () {
    $user = User::factory()->create();
    $this->travelTo(now()->setTime(23, 0));

    expect(NotificationThrottle::canPush($user, 'transit_disruption', 'critical'))->toBeTrue()
        ->and(NotificationThrottle::canPush($user, 'transit_disruption', 'major'))->toBeFalse();
});

test('weather alerts push only in commute windows and the Rhine never pushes', function () {
    $user = User::factory()->create();

    expect(NotificationThrottle::canPush($user, 'weather_alert'))->toBeFalse()
        ->and(NotificationThrottle::canPush($user, 'rhine_level'))->toBeFalse();

    $this->travelTo(now()->setTime(8, 0));

    expect(NotificationThrottle::canPush($user, 'weather_alert'))->toBeTrue();
});

test('a throttled bureaucracy push is not lost: the next run sends it', function () {
    Notification::fake();
    $userTask = overdueUserTask();

    holdPushThrottle($userTask->user_id);
    app(BureaucracyEvaluator::class)->evaluate($userTask->user, $userTask);

    expect(bureaucracyChannels($userTask))->not->toContain(ScoredAction::CHANNEL_PUSH);
    Notification::assertNothingSent();

    releasePushThrottle($userTask->user_id);
    app(BureaucracyEvaluator::class)->evaluate($userTask->user, $userTask);

    expect(bureaucracyChannels($userTask))->toContain(ScoredAction::CHANNEL_PUSH);
    Notification::assertSentToTimes($userTask->user, BureaucracyDeadlineNotification::class, 1);
});

test('a sent bureaucracy push consumes its tier', function () {
    Notification::fake();
    $userTask = overdueUserTask();

    app(BureaucracyEvaluator::class)->evaluate($userTask->user, $userTask);
    $sent = Notification::sent($userTask->user, BureaucracyDeadlineNotification::class)->first();

    // The queue worker fires this once the web push has gone out.
    event(new NotificationSent($userTask->user, $sent, WebPushChannel::class));

    releasePushThrottle($userTask->user_id);
    app(BureaucracyEvaluator::class)->evaluate($userTask->user, $userTask);

    expect(bureaucracyChannels($userTask))->not->toContain(ScoredAction::CHANNEL_PUSH);
    Notification::assertSentToTimes($userTask->user, BureaucracyDeadlineNotification::class, 1);
});

test('a queued bureaucracy push whose tier was already sent does not send again', function () {
    $user = User::factory()->create();
    $pushes = app(PushLedger::class);
    $pushes->delivered($pushes->reserve('bureaucracy_pushed:41:overdue', 30));

    $late = new BureaucracyDeadlineNotification(
        taskTitle: 'Register your address',
        tier: 'overdue',
        daysRemaining: -5,
        deadline: now()->subDays(5)->toDateString(),
        pushReservation: ['key' => 'bureaucracy_pushed:41:overdue', 'nonce' => 'a-different-reservation', 'sent_days' => 30],
    );

    expect($late->shouldSend($user, WebPushChannel::class))->toBeFalse()
        ->and($late->shouldSend($user, 'database'))->toBeTrue();
});

test('the same situation pushes once; only an escalation pushes again', function () {
    Notification::fake();
    $user = User::factory()->create();

    $delay = fn (string $severity) => new ScoredAction(
        type: 'transit_delay',
        actionKey: "delay:18:north:user:{$user->id}",
        score: 50.0,
        severity: $severity,
        validUntil: CarbonImmutable::now()->addHours(2),
        deliverChannels: [ScoredAction::CHANNEL_PUSH],
        payload: ['line' => '18', 'delay_min' => 30, 'stop_id' => 'Neumarkt'],
        createdAt: CarbonImmutable::now(),
    );
    $dispatch = fn (ScoredAction $action) => app(ScoredActionPushDispatcher::class)
        ->handle(new ScoredActionInserted($user, $action));

    $dispatch($delay('major'));
    $dispatch($delay('major'));

    Notification::assertSentToTimes($user, TransitDelayNotification::class, 1);

    $dispatch($delay('critical'));

    Notification::assertSentToTimes($user, TransitDelayNotification::class, 2);
});

test('a disruption that stays live is not announced again when its seen-list entry would have lapsed', function () {
    Event::fake([TransitDisruptionDetected::class]);
    $this->mock(DisruptionService::class, fn ($mock) => $mock->shouldReceive('getLineDisruptions')->andReturn([[
        'id' => 'news_9001',
        'title' => 'Line 5 disruption',
        'description' => 'Construction',
        'severity' => 'critical',
        'type' => 'line',
        'affected_lines' => ['5'],
        'started_at' => now()->toIso8601String(),
        'estimated_end' => null,
        'source' => 'kvb_news',
    ]]));

    // Polled every 10 minutes in production; 5-hour gaps straddle the 6-hour TTL.
    $this->artisan('transit:check-disruptions')->assertSuccessful();
    $this->travel(5)->hours();
    $this->artisan('transit:check-disruptions')->assertSuccessful();
    $this->travel(5)->hours();
    $this->artisan('transit:check-disruptions')->assertSuccessful();

    Event::assertDispatchedTimes(TransitDisruptionDetected::class, 1);
});
