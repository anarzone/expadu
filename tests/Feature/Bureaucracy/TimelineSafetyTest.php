<?php

use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Reminders\PlanReminderDelivery;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ActionBus;
use App\ContextEngine\ContextNotificationFactory;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use App\Notifications\BureaucracyPlanNotification;
use App\Support\NotificationThrottle;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\ReviewedHomePlan;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    Notification::fake();
    config(['context_engine.push_via_bus' => true]);
});

/** Canonical synthetic timeline; legacy rows below test only the old page adapter. */
function canonicalTimeline(?string $appointment = null, string $expiry = '2026-09-10'): array
{
    $actor = User::factory()->onboarded()->create(['situation' => null]);
    $fixture = ReviewedHomePlan::activate($actor, ['fixture.canonical.timeline' => [
        'applies_if' => [['citizenship_group' => 'non_eu']],
        'deadline_type' => 'fact_date', 'deadline_fact_key' => 'visa_expires_at',
    ]], ['citizenship_group' => 'non_eu', 'entry_mode' => 'd_visa', 'visa_expires_at' => $expiry]);
    $process = null;
    $appointmentId = (string) Str::uuid();
    if ($appointment !== null) {
        $process = app(ReconcileProcesses::class)->execute($actor, $fixture['case']->person, 'de-nrw-cologne')[0];
        app(RecordProcessEvent::class)->execute($actor, $process, 'appointment_recorded', [
            'appointment_id' => $appointmentId, 'starts_at' => $appointment, 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
        ], $process->version, (string) Str::uuid());
    }

    return [...$fixture, 'actor' => $actor, 'process' => $process, 'appointment_id' => $appointmentId];
}

function timelineActions(User $actor): Collection
{
    return collect(app(ActionBus::class)->topK($actor->id))->map(fn ($action) => [
        'action' => $action, 'event' => app(PlanReminderReference::class)->resolve($action->payload, $actor->id),
    ])->keyBy('event.kind');
}

function timelineSafetyRow(?string $appointment = null, string $expiry = '2026-09-10'): UserTask
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'non_eu_employee', 'is_eu' => false,
        'arrival_date' => '2026-08-01',
        'profile_attributes' => ['entry_mode' => 'd_visa', 'visa_expires_at' => $expiry],
    ]);
    $task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.timeline', 'applies_if' => [['citizenship_group' => 'non_eu']],
        'deadline_type' => 'permit_window', 'deadline_days' => 90,
    ]);

    return UserTask::factory()->for($user)->for($task)->create([
        'status' => 'not_started', 'is_applicable' => true, 'appointment_at' => $appointment,
    ]);
}

test('an appointment does not move the deadline in the model or checklist response', function () {
    $row = timelineSafetyRow('2026-10-15 10:30:00');
    expect($row->absolute_deadline?->toDateString())->toBe('2026-09-10');

    $this->actingAs($row->user)->get('/bureaucracy')->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks']['active'])->firstWhere('key', 'fixture.timeline');
        expect($card['deadline'])->toBe('2026-09-10')
            ->and($card['appointment_at'])->toStartWith('2026-10-15')
            ->and($card['deadline_note'])->not->toStartWith('Your appointment');

        return true;
    });
});

test('a deadline and upcoming appointment produce distinct reminders', function () {
    $fixture = canonicalTimeline('2026-09-09T10:30:00+02:00');
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    $actions = timelineActions($fixture['actor']);
    expect($actions)->toHaveCount(2)->and($actions->pluck('action.actionKey')->unique())->toHaveCount(2)
        ->and($actions['legal_due']['event']['date'])->toBe('2026-09-10')
        ->and($actions['legal_due']['event']['urgency'])->toBe('critical')
        ->and($actions['appointment']['event']['date'])->toStartWith('2026-09-09')
        ->and($actions['appointment']['event']['urgency'])->toBe('appointment_tomorrow');
    $notification = app(ContextNotificationFactory::class)->build($actions['legal_due']['action']);
    // Private dates belong in authenticated guidance, not lock-screen or queued text.
    expect($notification)->toBeInstanceOf(BureaucracyPlanNotification::class)
        ->and(json_encode($notification->toArray($fixture['actor'])))->not->toContain('2026-09-10', 'Due in 2');
});

test('a passed appointment does not silence an unresolved overdue deadline', function () {
    $fixture = canonicalTimeline('2026-09-07T10:30:00+02:00', '2026-09-01');
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    expect(timelineActions($fixture['actor'])->pluck('event.urgency')->all())->toBe(['overdue']);
});

test('age alone never downgrades an overdue deadline', function () {
    $row = timelineSafetyRow(null, '2026-01-01');
    expect($row->deadline_status['urgency'])->toBe('overdue')
        ->and($row->deadline_status['priority_boost'])->toBe(50);
});

test('a missing deadline anchor is unknown rather than no deadline', function () {
    $row = timelineSafetyRow();
    $row->user->update(['profile_attributes' => ['entry_mode' => 'd_visa']]);
    $row->unsetRelation('user');

    expect($row->absolute_deadline)->toBeNull()
        ->and($row->deadline_status['urgency'])->toBe('unknown')
        ->and($row->deadline_status['label'])->toBe('Deadline date unknown');
});

test('rescheduling an appointment removes its old action and queued reminder', function () {
    $fixture = canonicalTimeline('2026-09-09T10:30:00+02:00');
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    $action = timelineActions($fixture['actor'])['appointment']['action'];
    $notification = app(ContextNotificationFactory::class)->build($action);
    $process = $fixture['process']->fresh();
    app(RecordProcessEvent::class)->execute($fixture['actor'], $process, 'appointment_recorded', [
        'appointment_id' => $fixture['appointment_id'], 'starts_at' => '2026-10-01T10:30:00+02:00',
        'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
    ], $process->version, (string) Str::uuid());

    expect(timelineActions($fixture['actor'])->keys()->all())->toBe(['legal_due'])
        ->and($notification->shouldSend($fixture['actor'], 'database'))->toBeFalse();
});

test('old relative reminder wording is not sent or replayed the following day', function () {
    $fixture = canonicalTimeline();
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    $action = timelineActions($fixture['actor'])['legal_due']['action'];
    $notification = app(ContextNotificationFactory::class)->build($action);
    $this->travelTo('2026-09-09 10:00:00');

    expect(app(ActionBus::class)->topK($fixture['actor']->id))->toBeEmpty()
        ->and($notification->shouldSend($fixture['actor'], 'database'))->toBeFalse();
});

test('changed confirmed facts invalidate an already queued deadline', function () {
    $fixture = canonicalTimeline();
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    $notification = app(ContextNotificationFactory::class)->build(timelineActions($fixture['actor'])['legal_due']['action']);
    $case = $fixture['case']->fresh();
    app(CorrectFact::class)->execute($fixture['actor'], $case->person,
        $case->facts()->where('key', 'visa_expires_at')->where('state', 'confirmed')->firstOrFail()->id, '2027-01-01', $case->fact_version);

    expect(app(ActionBus::class)->topK($fixture['actor']->id))->toBeEmpty()
        ->and($notification->shouldSend($fixture['actor'], 'database'))->toBeFalse();
});

test('guidance is withdrawn when a confirmed answer makes the task no longer applicable', function () {
    $fixture = canonicalTimeline();
    app(BureaucracyEvaluator::class)->evaluate($fixture['actor']);
    expect(timelineActions($fixture['actor']))->toHaveCount(1);
    $case = $fixture['case']->fresh();
    app(CorrectFact::class)->execute($fixture['actor'], $case->person,
        $case->facts()->where('key', 'citizenship_group')->where('state', 'confirmed')->firstOrFail()->id, 'eu', $case->fact_version);

    expect(app(ActionBus::class)->topK($fixture['actor']->id))->toBeEmpty();
});

test('an unknown move-in date is not presented as a paused deadline', function () {
    $row = timelineSafetyRow();
    $row->task->update(['deadline_type' => 'days_since_move_in', 'deadline_days' => 14]);
    $row->user->update(['profile_attributes' => ['housing_status' => 'temporary']]);
    $this->actingAs($row->user)->get('/bureaucracy')->assertInertia(function ($page) {
        $card = collect($page->toArray()['props']['tasks']['active'])->firstWhere('key', 'fixture.timeline');
        expect($card['deadline_tier'])->toBe('needs_answer')
            ->and($card['deadline_note'])->toBe('Deadline date unknown. Add your actual move-in date to check the timing.');

        return true;
    });
});

test('a throttled appointment reminder remains eligible on the next evaluation', function () {
    config()->set('context_engine.push_via_bus', true);
    $fixture = canonicalTimeline('2026-09-08T15:30:00+02:00');
    $actor = $fixture['actor'];
    Redis::del(
        "notif_throttle:last:{$actor->id}",
        "notif_throttle:hour:{$actor->id}:".now()->format('Y-m-d-H'),
        "notif_throttle:day:{$actor->id}:".now()->format('Y-m-d'),
    );
    NotificationThrottle::recordSent($actor);
    $evaluator = app(BureaucracyEvaluator::class);
    $evaluator->evaluate($actor);
    Notification::assertNothingSent();
    expect(timelineActions($actor))->toHaveCount(2);
    foreach (timelineActions($actor) as $row) {
        expect($row['action']->deliverChannels)->not->toContain('push');
    }

    $this->travelTo('2026-09-08 10:16:00');
    $evaluator->evaluate($actor);
    Notification::assertSentTo($actor, BureaucracyPlanNotification::class,
        fn ($notification) => app(PlanReminderReference::class)->resolve($notification->reference, $actor->id)['urgency'] === 'appointment_today');
    Notification::assertSentToTimes($actor, BureaucracyPlanNotification::class, 2);
    // A queued job is not delivery. Simulate successful receipts explicitly.
    foreach (timelineActions($actor) as $row) {
        expect(app(PlanReminderDelivery::class)->delivered($row['action']->payload))->toBeTrue();
    }
    $this->travelTo('2026-09-08 11:00:00');
    $evaluator->evaluate($actor);
    Notification::assertSentToTimes($actor, BureaucracyPlanNotification::class, 2);
});
