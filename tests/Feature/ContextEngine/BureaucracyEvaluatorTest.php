<?php

use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;

uses()->group('context-engine');

beforeEach(function () {
    // Deterministic daytime, un-announced dedup cache, clean per-user ZSETs —
    // same hygiene as the other evaluator suites.
    $this->travelTo(now()->addDays(random_int(1000, 9999))->setTime(10, 0));
    Cache::flush();
    $prefix = (string) config('database.redis.options.prefix', '');
    Redis::eval(
        "for _,k in ipairs(redis.call('KEYS', ARGV[1])) do redis.call('DEL', k) end return 1",
        0,
        $prefix.'pending_actions:*'
    );
    Notification::fake();
});

function reminderUser(): User
{
    return User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee',
        'is_eu' => false,
        'arrival_date' => now()->subDays(10)->toDateString(),
    ]);
}

function overdueTask(): Task
{
    // Rule-based deadline: 5 days after arrival → 5 days overdue by now.
    return Task::factory()->create([
        'key' => 'x.reminder_target',
        'title' => 'Register your address',
        'is_published' => true,
        'deadline_type' => 'days_since_arrival',
        'deadline_days' => 5,
    ]);
}

function pendingActions(User $user): array
{
    return array_map(
        fn ($a) => $a->payload + ['tier' => $a->payload['tier'], 'channels' => $a->deliverChannels],
        app(ActionBus::class)->topK($user->id, 20),
    );
}

test('a booked appointment silences the rule-based overdue nag', function () {
    $user = reminderUser();
    $userTask = UserTask::create([
        'user_id' => $user->id,
        'task_id' => overdueTask()->id,
        // Booked for the 20th of next month — well past the rule-based date.
        'appointment_at' => now()->addDays(20),
    ]);

    app(BureaucracyEvaluator::class)->evaluate($user, $userTask->fresh());

    // 20 days out: no overdue nag, no appointment tier yet — silence.
    expect(pendingActions($user))->toBe([]);
});

test('the day before the appointment earns an appointment_tomorrow reminder', function () {
    $user = reminderUser();
    $userTask = UserTask::create([
        'user_id' => $user->id,
        'task_id' => overdueTask()->id,
        'appointment_at' => now()->addDay()->setTime(10, 30),
    ]);

    app(BureaucracyEvaluator::class)->evaluate($user, $userTask->fresh());

    $actions = pendingActions($user);
    expect($actions)->toHaveCount(1);
    expect($actions[0]['tier'])->toBe('appointment_tomorrow');
    expect($actions[0]['appointment_at'])->not->toBeNull();
});

test('the appointment day earns an appointment_today reminder with a push', function () {
    $user = reminderUser();
    $userTask = UserTask::create([
        'user_id' => $user->id,
        'task_id' => overdueTask()->id,
        'appointment_at' => now()->setTime(14, 0),
    ]);

    app(BureaucracyEvaluator::class)->evaluate($user, $userTask->fresh());

    $actions = pendingActions($user);
    expect($actions)->toHaveCount(1);
    expect($actions[0]['tier'])->toBe('appointment_today');
    expect($actions[0]['channels'])->toContain('push');
});

test('a passed appointment goes silent instead of nagging overdue', function () {
    $user = reminderUser();
    $userTask = UserTask::create([
        'user_id' => $user->id,
        'task_id' => overdueTask()->id,
        'appointment_at' => now()->subDays(2),
    ]);

    app(BureaucracyEvaluator::class)->evaluate($user, $userTask->fresh());

    expect(pendingActions($user))->toBe([]);
});

test('without an appointment, the rule-based overdue reminder still fires', function () {
    $user = reminderUser();
    $userTask = UserTask::create([
        'user_id' => $user->id,
        'task_id' => overdueTask()->id,
    ]);

    app(BureaucracyEvaluator::class)->evaluate($user, $userTask->fresh());

    $actions = pendingActions($user);
    expect($actions)->toHaveCount(1);
    expect($actions[0]['tier'])->toBe('overdue');
});
