<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyCase;
use App\Models\Task;
use App\Models\User;

/** A catalogue with one reviewed card per topic, applying to everyone. */
function notesCatalogue(array $topics): void
{
    $tasks = [];
    $mapping = [];
    foreach ($topics as $topic) {
        $key = 'fixture.notes.'.$topic;
        $tasks[] = Task::factory()->approvedFixture()->create(['key' => $key, 'title' => 'Synthetic '.$topic.' step',
            'type' => 'task', 'deadline_type' => 'none', 'applies_if' => [], 'depends_on' => [],
            'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
        $mapping[$key] = ['process_id' => $key, 'topic' => $topic, 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping))->id, null);
}

function notesPerson(User $user, string $citizenship): BureaucracyCase
{
    $case = app(EnsureAccountHolder::class)->dossier($user);
    app(RecordFactChange::class)->execute($user, $case->person, 'citizenship_group', $citizenship, null, $case->fresh()->fact_version);

    return $case->fresh();
}

beforeEach(fn () => $this->freezeTime());

test('a confirmed EU citizen with no residence work open is told no residence task applies', function () {
    notesCatalogue(['address', 'health']);
    $eu = User::factory()->create();
    $nonEu = User::factory()->create();

    expect(app(PlanReadModel::class)->for($eu, notesPerson($eu, 'eu')->person, 'de-nrw-cologne')['notes']['no_residence_task'])->toBeTrue()
        ->and(app(PlanReadModel::class)->for($nonEu, notesPerson($nonEu, 'non_eu')->person, 'de-nrw-cologne')['notes']['no_residence_task'])->toBeFalse();
});

test('residence work still open means no such note', function () {
    notesCatalogue(['address', 'residence']);
    $user = User::factory()->create();

    expect(app(PlanReadModel::class)->for($user, notesPerson($user, 'eu')->person, 'de-nrw-cologne')['notes']['no_residence_task'])->toBeFalse();
});

test('🔒 someone who may not see the answers learns nothing from the note', function () {
    notesCatalogue(['address']);
    $owner = User::factory()->create();
    $case = notesPerson($owner, 'eu');
    $helper = User::factory()->create();
    $workspace = app(EnsureAccountHolder::class)->dossier($helper)->person->workspace;
    $invite = app(ManageDelegation::class)->invite($helper, $workspace, $owner->email, ['view_plan']);
    app(ManageDelegation::class)->accept($owner, $invite['token'], ['view_plan']);

    $shared = app(PlanReadModel::class)->for($helper, $case->person, 'de-nrw-cologne');

    // Not false either: false would say "not an EU citizen" as plainly as true says it is.
    expect($shared['scopes'])->not->toContain('view_facts')
        ->and($shared['notes']['no_residence_task'])->toBeNull()
        ->and(app(PlanReadModel::class)->for($owner, $case->person, 'de-nrw-cologne')['notes']['no_residence_task'])->toBeTrue();
});
