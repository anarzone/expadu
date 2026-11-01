<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Reminders\PlanReminderReference;
use App\ContextEngine\ActionBus;
use App\ContextEngine\Evaluators\BureaucracyEvaluator;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses()->group('context-engine');

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    Notification::fake();
    config(['context_engine.push_via_bus' => true]);
    $this->actor = User::factory()->onboarded()->create(['city' => 'Köln', 'situation' => null]);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'arrival_date', now()->subDays(10)->toDateString(), null, 1);
    // Synthetic clock for reminder mechanics, not an actual legal deadline.
    $this->task = Task::factory()->approvedFixture()->create(['key' => 'fixture.reminder.target',
        'title' => 'Synthetic reviewed deadline', 'type' => 'task', 'applies_if' => [], 'depends_on' => [],
        'deadline_type' => 'days_since_arrival', 'deadline_days' => 5, 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $mapping = [$this->task->key => ['process_id' => 'fixture.reminder', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial',
        'temporal_policy' => ['kind' => 'legal_due', 'version' => 'synthetic-test.1',
            'content_version' => $this->task->content_version, 'reviewed_by' => $this->task->reviewed_by,
            'verified_at' => $this->task->verified_at->toDateString(),
            'source_url' => collect($this->task->legal_sources)->firstWhere('kind', 'primary')['url']]]];
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$this->task], $mapping));
    $store->activate($release->id, null);
});

function evaluatorAppointment($test, string $startsAt): void
{
    $process = app(ReconcileProcesses::class)->execute($test->actor, $test->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($test->actor, $process, 'appointment_recorded', [
        'appointment_id' => (string) Str::uuid(), 'starts_at' => $startsAt, 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
    ], $process->version, (string) Str::uuid());
}

function pendingActions(User $user): array
{
    return array_map(fn ($action) => [
        ...app(PlanReminderReference::class)->resolve($action->payload, $user->id), 'channels' => $action->deliverChannels,
    ], app(ActionBus::class)->topK($user->id, 20));
}

test('a booked appointment does not silence a separate overdue legal deadline', function () {
    evaluatorAppointment($this, now()->addDays(20)->toIso8601String());
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = pendingActions($this->actor);
    expect($actions)->toHaveCount(1)->and($actions[0]['kind'])->toBe('legal_due')
        ->and($actions[0]['urgency'])->toBe('overdue')->and($actions[0]['date'])->toBe(now()->subDays(5)->toDateString());
});

test('the day before an appointment retains both appointment and deadline reminders', function () {
    evaluatorAppointment($this, now()->addDay()->setTime(10, 30)->toIso8601String());
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = collect(pendingActions($this->actor))->keyBy('kind');
    expect($actions)->toHaveCount(2)->and($actions['appointment']['urgency'])->toBe('appointment_tomorrow')
        ->and($actions['legal_due']['urgency'])->toBe('overdue');
});

test('the appointment day earns an appointment today reminder eligible for push', function () {
    evaluatorAppointment($this, now()->setTime(14, 0)->toIso8601String());
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = collect(pendingActions($this->actor))->keyBy('kind');
    expect($actions['appointment']['urgency'])->toBe('appointment_today')
        ->and($actions['appointment']['channels'])->toContain('push')->and($actions['legal_due']['urgency'])->toBe('overdue');
});

test('a passed appointment stops its own reminder without cancelling the overdue deadline', function () {
    evaluatorAppointment($this, now()->subDays(2)->toIso8601String());
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = pendingActions($this->actor);
    expect($actions)->toHaveCount(1)->and($actions[0]['kind'])->toBe('legal_due')->and($actions[0]['urgency'])->toBe('overdue');
});

test('without an appointment the reviewed overdue deadline still creates a reminder', function () {
    app(BureaucracyEvaluator::class)->evaluate($this->actor);
    $actions = pendingActions($this->actor);
    expect($actions)->toHaveCount(1)->and($actions[0]['kind'])->toBe('legal_due')->and($actions[0]['urgency'])->toBe('overdue');
});

test('retained legacy progress and appointments do not alter replacement reminders', function () {
    $legacy = UserTask::factory()->completed()->create(['user_id' => $this->actor->id,
        'task_id' => $this->task->id, 'appointment_at' => now()->addDay()]);
    $before = $legacy->fresh()->getRawOriginal();
    app(BureaucracyEvaluator::class)->evaluate($this->actor, $legacy);
    $actions = pendingActions($this->actor);
    expect($actions)->toHaveCount(1)->and($actions[0]['kind'])->toBe('legal_due')->and($legacy->fresh()->getRawOriginal())->toBe($before);
});
