<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\PersonAccess;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Home\TodayPaperwork;
use App\Models\BureaucracyEvidenceItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

/* What to bring on Today: the documents of their own Paperwork task, ticked in the same record. */

/** A Cologne account tracking one reviewed task that needs two documents; its occurrence key. */
function bringHolder(): array
{
    $user = User::factory()->create(['city' => 'Köln']);
    $case = app(EnsureAccountHolder::class)->dossier($user);
    $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.bring', 'type' => 'task', 'applies_if' => [], 'depends_on' => [],
        'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [], 'documents_required' => [
            ['id' => 'passport', 'label' => 'Synthetic passport', 'evidence_kind' => 'synthetic.passport', 'requirement_version' => '1', 'note' => 'The original, not a copy.'],
            ['id' => 'photo', 'label' => 'Synthetic photo', 'evidence_kind' => 'synthetic.photo', 'requirement_version' => '1'],
        ]])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile([$task], [
        $task->key => ['process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
    ]))->id, null);
    $process = collect(app(ReconcileProcesses::class)->execute($user, $case->person, 'de-nrw-cologne'))->firstWhere('definition_id', 'fixture.bring');

    return [$user, (string) $process['occurrence_key']];
}

test('🔒 What to bring needs a token and a confirmed address', function () {
    $key = str_repeat('a', 64);
    $this->getJson("/api/v1/paperwork/{$key}/documents")->assertUnauthorized();

    $unconfirmed = User::factory()->unverified()->create();
    $this->withToken($unconfirmed->createToken('iPhone')->plainTextToken)->getJson("/api/v1/paperwork/{$key}/documents")->assertForbidden();
});

test('the list is the task’s required documents, counted as Today counts them', function () {
    [$user, $key] = bringHolder();

    $list = $this->withToken($user->createToken('iPhone')->plainTextToken)->getJson("/api/v1/paperwork/{$key}/documents")->assertOk()
        ->assertJsonPath('ready', 0)->assertJsonPath('total', 2)->json();
    expect(array_column($list['documents'], 'title'))->toBe(['Synthetic passport', 'Synthetic photo'])
        ->and($list['documents'][0]['note'])->toBe('The original, not a copy.')
        ->and($list['documents'][1]['note'])->toBeNull()
        ->and(collect(app(TodayPaperwork::class)->for($user)['processes'])->firstWhere('occurrence_key', $key)['documents'])->toBe(['ready' => 0, 'total' => 2]);
});

test('ticking a document marks it ready in Paperwork, and unticking takes it back', function () {
    [$user, $key] = bringHolder();
    $token = $user->createToken('iPhone')->plainTextToken;
    $id = $this->withToken($token)->getJson("/api/v1/paperwork/{$key}/documents")->json('documents.0.id');

    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk()
        ->assertJsonPath('ready', 1)->assertJsonPath('documents.0.ready', true)->assertJsonPath('documents.1.ready', false);
    // The same record Paperwork reads: Today's count moved too.
    expect(collect(app(TodayPaperwork::class)->for($user->fresh())['processes'])->firstWhere('occurrence_key', $key)['documents'])->toBe(['ready' => 1, 'total' => 2]);
    // Again is no change.
    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk()->assertJsonPath('ready', 1);

    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => false])->assertOk()
        ->assertJsonPath('ready', 0)->assertJsonPath('documents.0.ready', false);
    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk()->assertJsonPath('ready', 1);
});

test('ticking keeps what their record says, and unticking keeps the record: only ready goes', function () {
    [$user, $key] = bringHolder();
    $token = $user->createToken('iPhone')->plainTextToken;
    // They wrote their passport down in Paperwork before, with its expiry, not yet for this task.
    $person = app(EnsureAccountHolder::class)->dossier($user)->person;
    $passport = (string) Str::uuid();
    app(RecordEvidence::class)->execute($user, $person, $passport, ['label' => 'Synthetic passport', 'kind' => 'document',
        'reported_available' => false, 'expires_on' => '2031-04-30', 'requirement_refs' => []], 0, (string) Str::uuid());
    $id = $this->withToken($token)->getJson("/api/v1/paperwork/{$key}/documents")->json('documents.0.id');
    $record = fn () => BureaucracyEvidenceItem::query()->findOrFail($passport);

    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk()->assertJsonPath('documents.0.ready', true);
    expect($record()->details['expires_on'])->toBe('2031-04-30')
        ->and($record()->details['requirement_refs'])->toContain($id)
        ->and(BureaucracyEvidenceItem::query()->where('person_id', $person->id)->count())->toBe(1);

    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => false])->assertOk()->assertJsonPath('documents.0.ready', false);
    expect($record()->status)->toBe('active')
        ->and($record()->details['expires_on'])->toBe('2031-04-30')
        ->and($record()->details['requirement_refs'])->toContain($id);

    // Ticked again, it's the same record, as it was.
    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk()->assertJsonPath('documents.0.ready', true);
    expect(BureaucracyEvidenceItem::query()->where('person_id', $person->id)->count())->toBe(1)
        ->and($record()->details['expires_on'])->toBe('2031-04-30');
});

test('🔒 another person’s task can’t be read or ticked', function () {
    [$owner, $key] = bringHolder();
    $ownerToken = $owner->createToken('iPhone')->plainTextToken;
    $id = $this->withToken($ownerToken)->getJson("/api/v1/paperwork/{$key}/documents")->json('documents.0.id');
    $this->withToken($ownerToken)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertOk();
    // A task's key is the same for everyone; theirs isn't tracked, and the owner's record is never read.
    $other = User::factory()->create(['city' => 'Köln']);
    app(EnsureAccountHolder::class)->dossier($other);
    $stranger = User::factory()->create(['city' => 'Köln']);

    foreach ([$other, $stranger] as $user) {
        // A test reuses the app, so the last token's person would still be signed in.
        app('auth')->forgetGuards();
        $token = $user->createToken('iPhone')->plainTextToken;
        $this->withToken($token)->getJson("/api/v1/paperwork/{$key}/documents")->assertNotFound();
        $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => false])->assertNotFound();
    }
    expect(collect(app(TodayPaperwork::class)->for($owner->fresh())['processes'])->firstWhere('occurrence_key', $key)['documents'])->toBe(['ready' => 1, 'total' => 2]);
});

test('🔒 while Paperwork processing is stopped, the list says so and nothing is ticked', function () {
    [$user, $key] = bringHolder();
    $token = $user->createToken('iPhone')->plainTextToken;
    $id = $this->withToken($token)->getJson("/api/v1/paperwork/{$key}/documents")->json('documents.0.id');
    $person = app(EnsureAccountHolder::class)->person($user);
    $this->actingAs($user)->putJson("/paperwork/v2/people/{$person->id}/processing-restriction", ['stopped' => true])->assertOk();

    $this->withToken($token)->getJson("/api/v1/paperwork/{$key}/documents")->assertConflict()->assertJsonPath('message', PersonAccess::STOPPED);
    $this->withToken($token)->postJson("/api/v1/paperwork/{$key}/documents/{$id}", ['ready' => true])->assertConflict();
    expect(app(TodayPaperwork::class)->for($user->fresh()))->toBeNull();
});
