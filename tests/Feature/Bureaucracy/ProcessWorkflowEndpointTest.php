<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->create();
    $this->person = app(EnsureAccountHolder::class)->dossier($this->actor)->person;
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.workflow', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.workflow', 'topic' => 'residence', 'kind' => 'action', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
    $this->planUrl = '/bureaucracy/v2/people/'.$this->person->id.'/plan?jurisdiction=de-nrw-cologne';
    $this->actingAs($this->actor);
    $proposal = $this->getJson($this->planUrl)->assertSuccessful()->json('processes.0');
    $this->started = $this->postJson('/bureaucracy/v2/people/'.$this->person->id.'/processes', ['jurisdiction' => 'de-nrw-cologne',
        'occurrence_key' => $proposal['occurrence_key'], 'review_token' => $proposal['review_token'], 'request_id' => (string) Str::uuid()])
        ->assertSuccessful()->json();
    $this->url = '/bureaucracy/v2/processes/'.$this->started['process_id'];
    $this->report = function (string $event, array $payload = [], ?string $token = null) {
        $process = $this->getJson($this->url)->assertSuccessful()->json();

        return $this->postJson($this->url.'/events', ['request_id' => (string) Str::uuid(), 'expected_version' => $process['version'],
            'review_token' => $token ?? $process['review_token'], 'event' => $event, 'payload' => $payload]);
    };
});

test('blocked, resumed and cancelled progress keep the person\'s own note and date encrypted at rest', function () {
    ($this->report)('blocked_reported', ['note' => 'Waiting for my landlord letter', 'occurred_on' => '2026-09-07'])->assertSuccessful();
    $this->getJson($this->url)->assertJsonPath('state.workflow', 'blocked')
        ->assertJsonPath('state.report', ['event' => 'blocked_reported', 'occurred_on' => '2026-09-07', 'note' => 'Waiting for my landlord letter']);
    expect(json_encode(DB::table('bureaucracy_process_events')->get()))->not->toContain('landlord')
        ->and(json_encode(DB::table('bureaucracy_processes')->get()))->not->toContain('landlord');
    ($this->report)('preparation_started')->assertSuccessful();
    ($this->report)('cancellation_reported', ['occurred_on' => '2026-09-08', 'note' => 'Moved to Berlin'], '')->assertSuccessful();
    $this->getJson($this->url)->assertJsonPath('state.workflow', 'cancelled')->assertJsonPath('state.report.occurred_on', '2026-09-08');
});

test('invalid workflow reports are refused with a message the person can act on', function () {
    ($this->report)('waiting_reported')->assertUnprocessable()
        ->assertJsonPath('errors.event.0', 'Record the submission first. Waiting for the authority follows a reported submission.');
    ($this->report)('blocked_reported', ['note' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('payload.note');
    ($this->report)('blocked_reported', ['note' => '   '])->assertUnprocessable()->assertJsonValidationErrors('payload.note');
    ($this->report)('submission_recorded', ['occurred_on' => '2026-09-08'])->assertSuccessful();
    ($this->report)('blocked_reported')->assertUnprocessable()->assertJsonValidationErrors('event');
    $this->getJson($this->url)->assertJsonPath('state.workflow', 'submitted');
});

test('a mistaken submission can be withdrawn while its history stays stored', function () {
    ($this->report)('preparation_started')->assertSuccessful();
    ($this->report)('submission_recorded', ['occurred_on' => '2026-09-08', 'channel' => 'online'])->assertSuccessful();
    ($this->report)('waiting_reported')->assertSuccessful();
    $row = collect($this->getJson($this->planUrl)->json('timeline'))->firstWhere('kind', 'submission_recorded');
    expect($row['event_id'])->toBeInt()->and($row['revision_event_id'])->toBe($row['event_id'])
        ->and($row['id'])->toEndWith('submission:'.$row['event_id']);
    ($this->report)('submission_retracted', ['event_id' => $row['event_id'] + 1], '')->assertUnprocessable()->assertJsonValidationErrors('payload.event_id');
    ($this->report)('submission_retracted', ['event_id' => $row['event_id'], 'note' => 'Recorded on the wrong task'], '')->assertSuccessful();
    $this->getJson($this->url)->assertJsonPath('state.workflow', 'preparing')->assertJsonPath('state.report.event', 'submission_retracted');
    expect(collect($this->getJson($this->planUrl)->json('timeline'))->where('kind', 'submission_recorded'))->toBeEmpty()
        ->and(BureaucracyProcess::query()->find($this->started['process_id'])->events()->where('type', 'submission_recorded')->count())->toBe(1);
    ($this->report)('submission_retracted', ['event_id' => $row['event_id']], '')->assertUnprocessable();
});

test('a corrected submission keeps its identity and the next correction targets the latest revision', function () {
    ($this->report)('submission_recorded', ['occurred_on' => '2026-09-07'])->assertSuccessful();
    $row = collect($this->getJson($this->planUrl)->json('timeline'))->firstWhere('kind', 'submission_recorded');
    $version = $this->getJson($this->url)->json('version');
    $this->postJson($this->url.'/events/'.$row['revision_event_id'].'/corrections', ['request_id' => (string) Str::uuid(),
        'expected_version' => $version, 'confirmed' => true, 'payload' => ['occurred_on' => '2026-09-06']])->assertSuccessful();
    $corrected = collect($this->getJson($this->planUrl)->json('timeline'))->firstWhere('kind', 'submission_recorded');
    expect($corrected['event_id'])->toBe($row['event_id'])->and($corrected['revision_event_id'])->not->toBe($row['event_id'])
        ->and($corrected['date'])->toBe('2026-09-06');
    ($this->report)('submission_retracted', ['event_id' => $corrected['event_id']], '')->assertSuccessful();
    $this->getJson($this->url)->assertJsonPath('state.workflow', 'not_started');
});

test('starting to track can be undone before any progress and started again from a clean slate', function () {
    ($this->report)('process_untracked', [], '')->assertSuccessful();
    $plan = $this->getJson($this->planUrl)->assertSuccessful();
    expect($plan->json('processes.0.id'))->toBeNull()->and($plan->json('history'))->toBeEmpty();
    $this->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/events', ['request_id' => (string) Str::uuid(), 'expected_version' => 2,
        'review_token' => $plan->json('processes.0.review_token'), 'event' => 'preparation_started', 'payload' => []])->assertConflict();
    $restart = $this->postJson('/bureaucracy/v2/people/'.$this->person->id.'/processes', ['jurisdiction' => 'de-nrw-cologne',
        'occurrence_key' => $plan->json('processes.0.occurrence_key'), 'review_token' => $plan->json('processes.0.review_token'),
        'request_id' => (string) Str::uuid()])->assertSuccessful();
    expect($restart->json('process_id'))->toBe($this->started['process_id'])->and($restart->json('version'))->toBe(3);
    $this->getJson($this->url)->assertSuccessful()->assertJsonPath('state.workflow', 'not_started')->assertJsonPath('state.report', null);
    expect(BureaucracyProcess::query()->find($this->started['process_id'])->events()->orderBy('id')->pluck('type')->all())
        ->toBe(['process_started', 'process_untracked', 'process_started']);
});

test('the plan says whether starting to track can still be undone, by the same rule the command applies', function () {
    expect($this->getJson($this->planUrl)->json('processes.0.untrackable'))->toBeTrue();
    ($this->report)('preparation_started')->assertSuccessful();
    expect($this->getJson($this->planUrl)->json('processes.0.untrackable'))->toBeFalse();
    ($this->report)('process_untracked', [], '')->assertUnprocessable();
});

test('a process not yet started cannot be untracked', function () {
    ($this->report)('process_untracked', [], '')->assertSuccessful();
    expect($this->getJson($this->planUrl)->json('processes.0'))->toMatchArray(['id' => null, 'untrackable' => false]);
});

test('untracking is refused once progress was reported, so cancelling remains the honest path', function () {
    ($this->report)('preparation_started')->assertSuccessful();
    ($this->report)('process_untracked', [], '')->assertUnprocessable()->assertJsonValidationErrors('event');
    $this->getJson($this->url)->assertSuccessful()->assertJsonPath('state.workflow', 'preparing');
});

test('guidance tells the UI which report finishes a step, derived from the reviewed step kind', function () {
    $plan = $this->getJson($this->planUrl)->assertSuccessful();
    expect($plan->json('processes.0.guidance.0.kind'))->toBe('action')
        ->and($plan->json('processes.0.guidance.0.completion_event'))->toBe('submission_recorded')
        ->and($plan->json('guidance.0.completion_event'))->toBe('submission_recorded');
});

test('the plan lists exactly the progress changes the workflow accepts from here', function () {
    $events = fn () => collect($this->getJson($this->planUrl)->json('processes.0.progress_options'))->keyBy('event');

    expect($events()->keys()->all())->toBe(['preparation_started', 'blocked_reported', 'submission_recorded', 'cancellation_reported'])
        ->and($events()['submission_recorded'])->toMatchArray(['date' => 'required', 'note' => false, 'allowed' => true]);
    ($this->report)('preparation_started')->assertSuccessful();
    expect($events()->keys()->all())->toBe(['blocked_reported', 'submission_recorded', 'action_required_reported', 'completion_reported', 'cancellation_reported'])
        ->and($events()['completion_reported'])->toMatchArray(['allowed' => false, 'reason' => 'Confirm the individual steps before completing this process.']);
    ($this->report)('submission_recorded', ['occurred_on' => '2026-09-08'])->assertSuccessful();
    // After a submission: wait, the office needs something, complete or cancel; never "back to preparing".
    expect($events()->keys()->all())->toBe(['waiting_reported', 'action_required_reported', 'completion_reported', 'cancellation_reported']);
});

function activateRelease(array $tasks, array $mapping): void
{
    $store = app(CatalogueReleaseStore::class);
    $current = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->value('release_id');
    $hash = $current ? BureaucracyCatalogueRelease::query()->whereKey($current)->value('content_hash') : null;
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping))->id, $hash);
}

test('a new release that leaves this task unchanged never asks the person to review it', function () {
    $task = Task::query()->where('key', 'fixture.workflow')->sole();
    $other = Task::factory()->approvedFixture()->create(['key' => 'fixture.unrelated', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    activateRelease([$task, $other], [
        'fixture.workflow' => ['process_id' => 'fixture.workflow', 'topic' => 'residence', 'kind' => 'action', 'coverage' => 'partial'],
        'fixture.unrelated' => ['process_id' => 'fixture.unrelated', 'topic' => 'tax', 'kind' => 'action', 'coverage' => 'partial'],
    ]);

    $process = collect($this->getJson($this->planUrl)->json('processes'))->firstWhere('id', $this->started['process_id']);
    expect($process['guidance_state'])->toBe('current');
    ($this->report)('preparation_started')->assertSuccessful();
});

test('a change to this task\'s own steps asks for review before more progress, and review restores it', function () {
    $task = Task::query()->where('key', 'fixture.workflow')->sole();
    $task->forceFill(['description' => 'Changed guidance text.', 'content_version' => 'fixture.2'])->save();
    activateRelease([$task->fresh()], ['fixture.workflow' => ['process_id' => 'fixture.workflow', 'topic' => 'residence', 'kind' => 'action', 'coverage' => 'partial']]);

    $process = collect($this->getJson($this->planUrl)->json('processes'))->firstWhere('id', $this->started['process_id']);
    expect($process['guidance_state'])->toBe('review_required');
    ($this->report)('preparation_started')->assertConflict();
    $this->postJson($this->url.'/review', ['request_id' => (string) Str::uuid(), 'expected_version' => $process['version'],
        'review_token' => $process['review_token'], 'confirmed' => true])->assertSuccessful();
    expect(collect($this->getJson($this->planUrl)->json('processes'))->firstWhere('id', $this->started['process_id'])['guidance_state'])->toBe('current');
});
