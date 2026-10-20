<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Models\Task;

test('explicit versioned document semantics distinguish copy edits from changed requirements', function () {
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.paperwork', 'depends_on' => [], 'how_to_steps' => [], 'links' => [],
        'documents_required' => [['id' => 'identity', 'label' => 'Synthetic identity paper', 'evidence_kind' => 'synthetic.identity', 'requirement_version' => '1']]])->fresh();
    $compiler = app(CatalogueCompiler::class);
    $map = [$task->key => ['process_id' => 'fixture.paperwork', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial']];
    $before = $compiler->compile([$task], $map)['definitions'][0]['variants'][0]['documents'][0];
    $task->documents_required = [[...$task->documents_required[0], 'label' => 'Clearer synthetic identity wording']];
    $copy = $compiler->compile([$task], $map)['definitions'][0]['variants'][0]['documents'][0];
    expect($copy['semantic_hash'])->toBe($before['semantic_hash'])->and($copy['presentation_hash'])->not->toBe($before['presentation_hash']);
    $task->documents_required = [[...$task->documents_required[0], 'requirement_version' => '2']];
    expect($compiler->compile([$task], $map)['definitions'][0]['variants'][0]['documents'][0]['semantic_hash'])->not->toBe($before['semantic_hash']);
});

use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\ReadModel\ProcessReassessmentOutbox;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\BureaucracyOutboxEvent;
use App\Models\User;
use App\Models\UserTask;
use Illuminate\Support\Arr;

function catalogueFixture(string $key = 'fixture.prepare'): Task
{
    return Task::factory()->approvedFixture()->create([
        'key' => $key, 'title' => 'Synthetic preparation', 'description' => 'Synthetic test content, not legal guidance.',
        'applies_if' => [['citizenship_group' => 'non_eu']], 'deadline_type' => 'none',
        'documents_required' => [['id' => 'identity', 'label' => 'Synthetic identity document']],
        'how_to_steps' => [['id' => 'prepare', 'title' => 'Prepare', 'body' => 'Synthetic instruction.']],
        'depends_on' => [], 'links' => [],
    ])->fresh();
}

function catalogueMapping(string $key = 'fixture.prepare'): array
{
    return [$key => ['process_id' => 'fixture.process', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial', 'occurrence_fact' => 'current_residence_title']];
}

test('source snapshots round trip through the compiler without semantic drift', function () {
    $compiler = app(CatalogueCompiler::class);
    $artifact = $compiler->compile([catalogueFixture()], catalogueMapping());
    $again = $compiler->compile(array_map(fn ($entry) => new Task($entry['authored_record']), $artifact['inventory']), $artifact['mapping']);
    $expected = Arr::dot($artifact);
    $actual = Arr::dot($again);
    $differences = [];
    foreach (array_unique([...array_keys($expected), ...array_keys($actual)]) as $key) {
        if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
            $differences[$key] = [$expected[$key] ?? null, $actual[$key] ?? null];
        }
    }
    expect($differences)->toBe([]);
});

test('a release is immutable, activation is atomic and live review withdrawal removes guidance', function () {
    $task = catalogueFixture();
    $compiler = app(CatalogueCompiler::class);
    $store = app(CatalogueReleaseStore::class);
    $artifact = $compiler->compile([$task], catalogueMapping());
    $release = $store->stage($artifact);
    expect($store->current())->toBeNull();
    $store->activate($release->id, null);
    expect($store->current()['definitions'][0]['variants'][0]['task_key'])->toBe($task->key)
        ->and($store->stage($artifact)->id)->toBe($release->id);
    expect(fn () => $release->update(['artifact' => []]))->toThrow(LogicException::class);
    $task->update(['is_published' => false]);
    expect($store->current()['definitions'][0]['variants'])->toBe([])
        ->and($store->current()['withdrawn'])->toContain($task->key);
});

test('catalogue activation durably fans out reassessment and retries cannot duplicate it', function () {
    $store = app(CatalogueReleaseStore::class);
    $actor = User::factory()->onboarded()->create();
    $person = app(EnsureAccountHolder::class)->dossier($actor)->person;
    $release = $store->stage(app(CatalogueCompiler::class)->compile([catalogueFixture()], catalogueMapping()));
    $store->activate($release->id, null);
    $store->activate($release->id, null);
    $event = BureaucracyOutboxEvent::query()->where('event_type', 'catalogue.activated')->sole();
    $worker = app(ProcessReassessmentOutbox::class);
    expect($worker->process($event))->toBeTrue()->and($worker->process($event))->toBeFalse();
    $target = BureaucracyOutboxEvent::query()->where('event_type', 'person.reassessment_requested')->sole();
    expect($target->aggregate_id)->toBe($person->id)->and($target->payload)->toBe([]);
});

test('invalid criteria cannot replace an active release and partial criteria cannot claim complete coverage', function () {
    $task = catalogueFixture();
    $compiler = app(CatalogueCompiler::class);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage($compiler->compile([$task], catalogueMapping()));
    $store->activate($release->id, null);
    $task->applies_if = [['invented_status' => 'eligible']];
    expect(fn () => $compiler->compile([$task], catalogueMapping()))->toThrow(DomainException::class);
    $task->refresh();
    $map = catalogueMapping();
    $map[$task->key]['coverage'] = 'complete';
    expect(fn () => $compiler->compile([$task], $map))->toThrow(DomainException::class)
        ->and(BureaucracyCatalogueRelease::query()->count())->toBe(1);
    expect(fn () => $compiler->compile([$task, $task], catalogueMapping()))->toThrow(DomainException::class);
});

test('complete coverage requires a nonempty source review reference and at least one assessable criterion', function (array $conditions, mixed $reference) {
    $task = catalogueFixture();
    $task->applies_if = $conditions;
    $map = catalogueMapping();
    $map[$task->key]['coverage'] = 'complete';
    $map[$task->key]['coverage_review'] = ['criterion_keys' => array_values(array_unique(array_merge(...array_map(array_keys(...), $conditions)))),
        'content_version' => $task->content_version, 'reviewed_by' => $task->reviewed_by,
        'source_review_reference' => $reference];
    expect(fn () => app(CatalogueCompiler::class)->compile([$task], $map))->toThrow(DomainException::class);
})->with([
    [[['citizenship_group' => 'non_eu']], ''],
    [[['citizenship_group' => 'non_eu']], '   '],
    [[['case_goal' => 'settlement_permit']], 'synthetic-review'],
    [[['case_goal' => 'settlement_permit'], ['citizenship_group' => 'non_eu']], 'synthetic-review'],
]);

test('invalid branches, duplicate document IDs and dependency cycles fail closed', function (string $defect) {
    $task = catalogueFixture();
    match ($defect) {
        'branch' => $task->documents_required = [['id' => 'identity', 'label' => 'ID', 'branch' => 'invented']],
        'duplicate document' => $task->documents_required = [['id' => 'same', 'label' => 'One'], ['id' => 'same', 'label' => 'Two']],
        'cycle' => $task->depends_on = [$task->key],
        'jurisdiction' => $task->jurisdiction = 'invented-city',
    };
    expect(fn () => app(CatalogueCompiler::class)->compile([$task], catalogueMapping()))->toThrow(DomainException::class);
})->with(['branch', 'duplicate document', 'cycle', 'jurisdiction']);

test('all local records are inventoried without promoting legacy prose', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $tasks = Task::query()->whereNotNull('key')->orderBy('key')->get();
    $artifact = app(CatalogueCompiler::class)->compile($tasks->all());
    expect($artifact['inventory'])->toHaveCount(95)
        ->and(collect($artifact['inventory'])->where('review_status', 'legacy'))->toHaveCount(47)
        ->and(collect($artifact['definitions'])->flatMap(fn ($definition) => $definition['variants'])->every(fn ($variant) => $variant['review']['review_status'] === 'approved'))->toBeTrue();
});

test('retiring catalogue entries retains existing user progress and never deletes task rows', function () {
    $task = Task::factory()->create(['key' => 'fixture.retired', 'is_published' => true]);
    $progress = UserTask::factory()->completed()->create(['task_id' => $task->id, 'user_id' => User::factory()->onboarded()->create()->id]);
    $this->artisan('bureaucracy:import-tasks', ['--retire-missing' => true])->assertSuccessful();
    expect($task->fresh()->is_published)->toBeFalse()->and($progress->fresh()->completed_at)->not->toBeNull();
});

test('modified compiled conditions are rejected instead of trusting a copied source hash', function () {
    $task = catalogueFixture();
    $artifact = app(CatalogueCompiler::class)->compile([$task], catalogueMapping());
    $artifact['definitions'][0]['variants'][0]['conditions'] = [];
    expect(fn () => app(CatalogueReleaseStore::class)->stage($artifact))->toThrow(DomainException::class);
    expect(BureaucracyCatalogueRelease::query()->count())->toBe(0);
});

test('changing approved prose under the same version rolls back the entire import', function () {
    $this->artisan('bureaucracy:import-tasks')->assertSuccessful();
    $task = Task::query()->where('key', 'core.anmeldung')->sole();
    $task->update(['description' => 'Synthetic newer edited text with no version bump.']);
    $before = Task::query()->orderBy('id')->get()->toArray();
    $this->artisan('bureaucracy:import-tasks', ['--retire-missing' => true])->assertFailed();
    expect(Task::query()->orderBy('id')->get()->toArray())->toBe($before);
});

test('unapproved instruction URLs are absent from compiled public guidance', function () {
    $task = catalogueFixture();
    $task->update(['how_to_steps' => [['title' => 'Synthetic instruction', 'link' => 'https://unreviewed.invalid/']]]);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task->fresh()], catalogueMapping()));
    $store->activate($release->id, null);
    $definition = $store->current()['definitions'][0];
    expect(json_encode($definition))->not->toContain('unreviewed.invalid')
        ->and($definition['variants'][0]['unavailable_actions'])->toHaveCount(1);
});

test('removing an action host withholds existing actions without needing a new task version', function () {
    $task = catalogueFixture();
    $task->update(['links' => ['https://www.stadt-koeln.de/service/']]);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task->fresh()], catalogueMapping()));
    $store->activate($release->id, null);
    expect($store->current()['definitions'][0]['variants'][0]['actions'])->toHaveCount(1);
    config(['bureaucracy_catalogue.action_hosts' => []]);
    expect($store->current()['definitions'][0]['variants'][0]['actions'])->toBe([]);
});

test('a retired approved unit remains inventoried without preventing a new release', function () {
    $task = catalogueFixture();
    $task->update(['is_published' => false]);
    $artifact = app(CatalogueCompiler::class)->compile([$task->fresh()], []);
    expect($artifact['inventory'][0]['status'])->toBe('retired')->and($artifact['definitions'])->toBe([]);
    expect(app(CatalogueReleaseStore::class)->stage($artifact)->exists)->toBeTrue();
});

test('stable IDs reject trailing newlines', function (string $target) {
    $task = catalogueFixture();
    $map = catalogueMapping();
    match ($target) {
        'task' => $task->key = "fixture.prepare\n",
        'process' => $map[$task->key]['process_id'] = "fixture.process\n",
        'instruction' => $task->how_to_steps = [['id' => "prepare\n", 'title' => 'Prepare']],
        'document' => $task->documents_required = [['id' => "identity\n", 'label' => 'ID']],
    };
    if ($target === 'task') {
        $map = [$task->key => catalogueMapping()['fixture.prepare']];
    }
    expect(fn () => app(CatalogueCompiler::class)->compile([$task], $map))->toThrow(DomainException::class);
})->with(['task', 'process', 'instruction', 'document']);
