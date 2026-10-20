<?php

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\Processes\CorrectProcessEvent;
use App\Bureaucracy\Processes\DiscoverProcesses;
use App\Bureaucracy\Processes\ProcessHistory;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Processes\ReviewProcessChanges;
use App\Models\BureaucracyProcess;
use App\Models\BureaucracyProcessEvent;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function processFixture(): array
{
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.process.prepare', 'type' => 'task',
        'applies_if' => [['current_residence_title' => 'blue_card']], 'depends_on' => [], 'deadline_type' => 'none',
        'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $catalogues = app(CatalogueReleaseStore::class);
    $release = $catalogues->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.process', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
        'occurrence_fact' => 'current_residence_title',
    ]]));
    $catalogues->activate($release->id, null);

    return [$actor, $case, $task];
}

test('a correction retains occurrence identity while a real renewal creates a new one', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $one = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2025-01-01', 1);
    expect($one->context_id)->not->toBeNull();
    $fixed = app(CorrectFact::class)->execute($actor, $case->person, $one->id, 'standard_work_permit', 2);
    expect($fixed->context_id)->toBe($one->context_id);
    $renewal = app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'standard_work_permit', '2026-01-01', 3);
    expect($renewal->context_id)->not->toBe($one->context_id)
        ->and(app(ConfirmedFactView::class)->forCase($case, now()->toDateString())['evidence']['current_residence_title']['context_id'])->toBe($renewal->context_id);
});

test('process discovery is idempotent and a renewal preserves the prior completed occurrence', function () {
    [$actor, $case] = processFixture();
    $reconcile = app(ReconcileProcesses::class);
    $one = $reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0];
    expect($reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0]->id)->toBe($one->id);
    app(RecordProcessEvent::class)->execute($actor, $one, 'step_completed', ['step_id' => 'fixture.process.prepare.complete'], 1, (string) Str::uuid());
    app(RecordProcessEvent::class)->execute($actor, $one, 'completion_reported', [], 2, (string) Str::uuid());
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2026-01-01', 2);
    $two = $reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0];
    expect($two->id)->not->toBe($one->id)->and($two->state['workflow'])->toBe('not_started')
        ->and($one->fresh()->state['workflow'])->toBe('completed')->and(BureaucracyProcess::query()->count())->toBe(2);
});

test('a changed actionable step set requires review before recording progress', function () {
    [$actor, $case, $one] = processFixture();
    app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'b1', null, 2);
    $two = Task::factory()->approvedFixture()->create(['key' => 'fixture.process.extra', 'type' => 'task',
        'applies_if' => [['current_residence_title' => 'blue_card', 'german_level' => 'b1']], 'depends_on' => [],
        'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $previous = $store->current()['release_hash'];
    $map = ['process_id' => 'fixture.process', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
        'occurrence_fact' => 'current_residence_title'];
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$one, $two], [$one->key => $map, $two->key => $map]));
    $store->activate($release->id, $previous);
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    expect($process->state['steps'])->toHaveCount(2);
    app(RecordFactChange::class)->execute($actor, $case->person, 'german_level', 'a1', null, 3);
    expect(fn () => app(RecordProcessEvent::class)->execute($actor, $process, 'step_completed',
        ['step_id' => $one->key.'.complete'], 1, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
    expect($process->events()->count())->toBe(0)->and($process->fresh()->version)->toBe(1);
    $proposal = app(DiscoverProcesses::class)->for(
        app(PrepareAssessmentInput::class)->for($actor, $case->person, $process->jurisdiction))[0];
    $requestId = (string) Str::uuid();
    $review = app(ReviewProcessChanges::class);
    $event = $review->execute($actor, $process, 1, $requestId, $proposal['review_token']);
    expect($review->execute($actor, $process, 1, $requestId, $proposal['review_token'])->id)->toBe($event->id)
        ->and($process->fresh()->state['steps'])->toBe([$one->key.'.complete' => 'todo']);
    app(RecordProcessEvent::class)->execute($actor, $process, 'step_completed', ['step_id' => $one->key.'.complete'], 2, (string) Str::uuid());
    expect($process->fresh()->state['steps'])->toBe([$one->key.'.complete' => 'completed']);
});

test('correcting a reported submission preserves the original event and timeline identity', function () {
    [$actor, $case] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    $first = app(RecordProcessEvent::class)->execute($actor, $process, 'submission_recorded', ['occurred_on' => '2026-01-01'], 1, (string) Str::uuid());
    $request = (string) Str::uuid();
    $correct = app(CorrectProcessEvent::class);
    $fixed = $correct->execute($actor, $process, $first->id, ['occurred_on' => '2026-01-02'], 2, $request);
    expect($first->fresh()->payload['occurred_on'])->toBe('2026-01-01')
        ->and($fixed->corrects_event_id)->toBe($first->id)
        ->and($correct->execute($actor, $process, $first->id, ['occurred_on' => '2026-01-02'], 2, $request)->id)->toBe($fixed->id)
        ->and($process->fresh()->state['workflow'])->toBe('submitted');
    // Eloquent intentionally hides payloads. The explicitly authorised projection supplies them.
    $active = app(ProcessHistory::class)->active($process->events()->orderBy('id')->get()->map(fn ($event) => [
        'id' => $event->id, 'type' => $event->type, 'payload' => $event->payload, 'corrects_event_id' => $event->corrects_event_id,
    ])->all());
    expect($active)->toHaveCount(1)->and($active[0]['id'])->toBe($first->id)
        ->and($active[0]['revision_event_id'])->toBe($fixed->id)->and($active[0]['payload']['occurred_on'])->toBe('2026-01-02');
    expect(fn () => $correct->execute($actor, $process, $first->id, ['occurred_on' => '2026-01-03'], 3, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
});

test('progress and its correction history are exported and erased with their subject', function () {
    [$actor, $case] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    $first = app(RecordProcessEvent::class)->execute($actor, $process, 'submission_recorded', ['occurred_on' => '2026-01-01'], 1, (string) Str::uuid());
    app(CorrectProcessEvent::class)->execute($actor, $process, $first->id, ['occurred_on' => '2026-01-02'], 2, (string) Str::uuid());
    $lifecycle = app(PersonDataLifecycle::class);
    expect($lifecycle->export($actor, $case->person)['processes'][0]['events'])->toHaveCount(2);
    $lifecycle->erase($actor, $case->person);
    expect($process->fresh())->toBeNull()->and(BureaucracyProcessEvent::query()->count())->toBe(0);
});

test('event retries do not double count and cannot be replayed with a different body', function () {
    [$actor, $case] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    $events = app(RecordProcessEvent::class);
    $request = strtoupper((string) Str::uuid());
    $payload = ['step_id' => 'fixture.process.prepare.complete'];
    $one = $events->execute($actor, $process, 'step_completed', $payload, 1, $request);
    expect($events->execute($actor, $process, 'step_completed', $payload, 1, $request)->id)->toBe($one->id)
        ->and(BureaucracyProcessEvent::query()->count())->toBe(1)->and($process->fresh()->version)->toBe(2);
    expect(fn () => $events->execute($actor, $process, 'step_reopened', $payload, 1, $request))->toThrow(ConflictHttpException::class);
});

test('foreign actors and withdrawn guidance cannot accept new completion commands', function () {
    [$actor, $case, $task] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    $events = app(RecordProcessEvent::class);
    $payload = ['step_id' => 'fixture.process.prepare.complete'];
    expect(fn () => $events->execute(User::factory()->create(), $process, 'step_completed', $payload, 1, (string) Str::uuid()))->toThrow(AuthorizationException::class);
    $task->update(['is_published' => false]);
    expect(fn () => $events->execute($actor, $process, 'step_completed', $payload, 1, (string) Str::uuid()))->toThrow(ConflictHttpException::class);
    expect(BureaucracyProcessEvent::query()->count())->toBe(0)->and($case->facts()->count())->toBe(1);
});

test('a prerequisite uses only the current occurrence and is refreshed before each command', function () {
    [$actor, $case, $parent] = processFixture();
    $child = Task::factory()->approvedFixture()->create(['key' => 'fixture.child', 'type' => 'task',
        'applies_if' => [['current_residence_title' => 'blue_card']], 'depends_on' => [$parent->key],
        'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $previous = $store->current()['release_hash'];
    $mapping = ['topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial', 'occurrence_fact' => 'current_residence_title'];
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$parent, $child], [
        $parent->key => [...$mapping, 'process_id' => 'fixture.process'], $child->key => [...$mapping, 'process_id' => 'fixture.child'],
    ]));
    $store->activate($release->id, $previous);
    $processes = collect(app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne'))->keyBy('definition_id');
    $events = app(RecordProcessEvent::class);
    $payload = ['step_id' => $child->key.'.complete'];
    expect(fn () => $events->execute($actor, $processes['fixture.child'], 'step_completed', $payload, 1, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
    $events->execute($actor, $processes['fixture.process'], 'step_completed', ['step_id' => $parent->key.'.complete'], 1, (string) Str::uuid());
    $events->execute($actor, $processes['fixture.child'], 'step_completed', $payload, 1, (string) Str::uuid());
    expect($processes['fixture.child']->fresh()->state['steps'][$child->key.'.complete'])->toBe('completed');
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2026-01-01', 2);
    $next = collect(app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne'))->keyBy('definition_id');
    expect(fn () => $events->execute($actor, $next['fixture.child'], 'step_completed', $payload, 1, (string) Str::uuid()))
        ->toThrow(ValidationException::class);
});

test('learning a missing date offers explicit continuity review instead of another empty process', function () {
    $actor = User::factory()->create();
    $case = app(EnsureAccountHolder::class)->dossier($actor);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.move', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.move', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial', 'occurrence_fact' => 'moved_in_at',
    ]]));
    $store->activate($release->id, null);
    $reconcile = app(ReconcileProcesses::class);
    $process = $reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($actor, $process, 'step_completed', ['step_id' => $task->key.'.complete'], 1, (string) Str::uuid());
    app(RecordFactChange::class)->execute($actor, $case->person, 'moved_in_at', '2026-01-01', null, 1);
    expect($reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0]->id)->toBe($process->id);
    $proposal = app(DiscoverProcesses::class)->for(
        app(PrepareAssessmentInput::class)->for($actor, $case->person, $process->jurisdiction))[0];
    app(ReviewProcessChanges::class)->execute($actor, $process, 2, (string) Str::uuid(), $proposal['review_token'], $proposal['occurrence_key']);
    expect($process->fresh()->occurrence_key)->toBe($proposal['occurrence_key'])
        ->and($process->fresh()->state['steps'][$task->key.'.complete'])->toBe('completed')->and(BureaucracyProcess::query()->count())->toBe(1);
    app(RecordFactChange::class)->execute($actor, $case->person, 'moved_in_at', '2026-02-01', null, 2);
    expect($reconcile->execute($actor, $case->person, 'de-nrw-cologne')[0]->id)->not->toBe($process->id);
});

test('an old appointment can still be cancelled after the person records a new renewal', function () {
    [$actor, $case] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    $appointmentId = (string) Str::uuid();
    app(RecordProcessEvent::class)->execute($actor, $process, 'appointment_recorded', ['appointment_id' => $appointmentId,
        'starts_at' => '2026-10-01T10:00:00+02:00', 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30], 1, (string) Str::uuid());
    app(RecordFactChange::class)->execute($actor, $case->person, 'current_residence_title', 'blue_card', '2026-01-01', 2);
    $url = '/bureaucracy/v2/processes/'.$process->id;
    $this->actingAs($actor)->getJson($url)->assertSuccessful()->assertJsonPath('guidance_state', 'history_only')->assertJsonPath('review_token', null);
    $requestId = (string) Str::uuid();
    app(RecordProcessEvent::class)->execute($actor, $process, 'appointment_cancelled', ['appointment_id' => $appointmentId], 2, $requestId);
    $this->postJson($url.'/events', ['request_id' => $requestId, 'expected_version' => 2,
        'event' => 'appointment_cancelled', 'payload' => ['appointment_id' => $appointmentId]])->assertSuccessful();
    expect($process->events()->orderByDesc('id')->first()->type)->toBe('appointment_cancelled');
});

test('explicitly separated provisional history never offers an impossible continuity review', function () {
    [$actor, $case] = processFixture();
    $process = app(ReconcileProcesses::class)->execute($actor, $case->person, 'de-nrw-cologne')[0];
    // Simulate the originally unknown occurrence, preserving the already recorded subject and definition.
    $process->update(['context_id' => 'unbound:current_residence_title', 'occurrence_key' => str_repeat('a', 64)]);
    $separate = app(ReconcileProcesses::class)->execute($actor, $case->person, $process->jurisdiction, [$process->id])[0];
    expect($separate->id)->not->toBe($process->id);
    $this->actingAs($actor)->getJson('/bureaucracy/v2/processes/'.$process->id)->assertSuccessful()
        ->assertJsonPath('guidance_state', 'history_only')->assertJsonPath('bind_occurrence', null)->assertJsonPath('review_token', null);
});
