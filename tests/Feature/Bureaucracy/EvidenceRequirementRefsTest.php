<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\PaperworkReadModel;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Models\BureaucracyRequirementUse;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $tasks = [];
    $mapping = [];
    foreach (['one', 'two'] as $suffix) {
        // Free-text catalogue documents without a reviewed evidence kind, as in the current catalogue.
        $tasks[] = $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.refs.'.$suffix, 'type' => 'task', 'applies_if' => [],
            'depends_on' => [], 'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [],
            'documents_required' => ['Synthetic passport', 'Synthetic contract']])->fresh();
        $mapping[$task->key] = ['process_id' => $task->key, 'position' => 1, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping))->id, null);
    $this->paperwork = fn () => collect(app(PaperworkReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    $this->url = fn (string $id) => '/bureaucracy/v2/people/'.$this->case->person_id.'/evidence/'.$id;
});

test('one evidence item attached to requirements by reference shows as available on each of them without confirming any use', function () {
    $id = (string) Str::uuid();
    $refs = ['fixture.refs.one.document.1', 'fixture.refs.two.document.1'];
    $this->actingAs($this->actor)->putJson(($this->url)($id), ['request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'details' => ['label' => 'Passport', 'kind' => 'passport', 'reported_available' => true, 'requirement_refs' => $refs]])->assertSuccessful();
    $rows = ($this->paperwork)();
    expect($rows['fixture.refs.one.document.1'])->toMatchArray(['readiness' => 'reported_available', 'suggested_evidence_ids' => [$id], 'evidence_id' => null])
        ->and($rows['fixture.refs.two.document.1'])->toMatchArray(['readiness' => 'reported_available', 'suggested_evidence_ids' => [$id]])
        ->and($rows['fixture.refs.one.document.2'])->toMatchArray(['readiness' => 'missing', 'suggested_evidence_ids' => []])
        ->and(BureaucracyRequirementUse::query()->count())->toBe(0);
    $process = collect(app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne'))->firstWhere('definition_id', 'fixture.refs.one');
    $row = ($this->paperwork)()['fixture.refs.one.document.1'];
    app(ConfirmRequirementUse::class)->execute($this->actor, $process, $row['id'], $id, $process->version, 1, $row['semantic_hash'], (string) Str::uuid());
    $rows = ($this->paperwork)();
    expect($rows['fixture.refs.one.document.1']['readiness'])->toBe('confirmed_for_use')
        ->and($rows['fixture.refs.two.document.1']['readiness'])->toBe('reported_available');
});

test('an item without a matching reference or kind, or reported unavailable, is not suggested', function (array $details) {
    $this->actingAs($this->actor)->putJson(($this->url)((string) Str::uuid()), ['request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'details' => ['label' => 'Passport', 'kind' => 'passport', ...$details]])->assertSuccessful();
    expect(($this->paperwork)()->pluck('readiness')->unique()->values()->all())->toBe(['missing']);
})->with([
    'no references' => [['reported_available' => true]],
    'other references' => [['reported_available' => true, 'requirement_refs' => ['fixture.refs.elsewhere.document.1']]],
    'not available' => [['reported_available' => false, 'requirement_refs' => ['fixture.refs.one.document.1']]],
]);

test('requirement references must be short identifiers', function (mixed $refs) {
    $this->actingAs($this->actor)->putJson(($this->url)((string) Str::uuid()), ['request_id' => (string) Str::uuid(), 'expected_version' => 0,
        'details' => ['label' => 'Passport', 'kind' => 'passport', 'reported_available' => true, 'requirement_refs' => $refs]])->assertUnprocessable();
})->with([
    'not a list' => [['a' => 'fixture.refs.one.document.1']],
    'free text' => [['My passport scan, number X123']],
    'too many' => [array_fill(0, 51, 'fixture.refs.one.document.1')],
]);
