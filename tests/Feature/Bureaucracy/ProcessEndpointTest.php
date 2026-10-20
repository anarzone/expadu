<?php

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.endpoint', 'type' => 'task', 'applies_if' => [],
        'depends_on' => [], 'deadline_type' => 'none', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
        'process_id' => 'fixture.endpoint', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
    $this->process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    $this->url = '/bureaucracy/v2/processes/'.$this->process->id;
});

test('process reads are private and progress reports remain explicit through HTTP', function () {
    $read = $this->actingAs($this->actor)->getJson($this->url)->assertSuccessful()->assertJsonPath('guidance_state', 'current')->json();
    expect($this->process->events()->count())->toBe(0);
    $command = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'review_token' => $read['review_token'],
        'event' => 'step_completed', 'payload' => ['step_id' => 'fixture.endpoint.complete']];
    $event = $this->postJson($this->url.'/events', $command)->assertSuccessful()->json('event_id');
    $this->postJson($this->url.'/events', $command)->assertSuccessful()->assertJsonPath('event_id', $event);
    $view = $this->getJson($this->url)->assertSuccessful()->assertJsonPath('state.workflow', 'preparing');
    expect($view->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $this->postJson($this->url.'/events', [...$command, 'request_id' => (string) Str::uuid()])->assertConflict();
});

test('foreign actors cannot read or alter a process and invalid reports are not flashed', function () {
    $this->actingAs(User::factory()->create())->getJson($this->url)->assertForbidden();
    $this->postJson($this->url.'/events', ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'review_token' => str_repeat('a', 64),
        'event' => 'preparation_started', 'payload' => []])->assertForbidden();
    $this->actingAs($this->actor)->post($this->url.'/events', ['event' => 'authority_approval_verified',
        'payload' => ['reference' => 'private-unverified-claim']], ['Accept' => 'text/html'])->assertUnprocessable()->assertSessionMissing('_old_input');
    expect($this->process->events()->count())->toBe(0);
});

test('reported appointments reject impossible dates and offsets and cannot cancel another process appointment', function () {
    $read = $this->actingAs($this->actor)->getJson($this->url)->assertSuccessful()->json();
    $command = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'review_token' => $read['review_token'], 'event' => 'appointment_recorded',
        'payload' => ['appointment_id' => (string) Str::uuid(), 'starts_at' => '2026-03-29T02:30:00+01:00', 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30]];
    $this->postJson($this->url.'/events', $command)->assertUnprocessable()->assertJsonValidationErrors('payload.starts_at');
    $this->postJson($this->url.'/events', [...$command, 'event' => 'appointment_cancelled', 'payload' => ['appointment_id' => (string) Str::uuid()]])->assertUnprocessable();
    $command['payload']['starts_at'] = '2026-10-25T02:30:00+02:00';
    $this->postJson($this->url.'/events', $command)->assertSuccessful();
    expect($this->process->fresh()->state['workflow'])->toBe('not_started');
    $input = app(PrepareAssessmentInput::class)->for($this->actor, $this->case->person, $this->process->jurisdiction);
    expect($input->processes[0]['version'])->toBe(2);
});
