<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\PaperworkReadModel;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\Evidence\ShareEvidence;
use App\Bureaucracy\Evidence\WithdrawRequirementUse;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\PersonDataLifecycle;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Models\BureaucracyEvidenceItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $tasks = [];
    $mapping = [];
    foreach (['one', 'two'] as $suffix) {
        $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.'.$suffix, 'type' => 'task', 'applies_if' => [], 'depends_on' => [],
            'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [], 'documents_required' => [
                ['id' => 'identity', 'label' => 'Synthetic identity paper', 'evidence_kind' => 'synthetic.identity', 'requirement_version' => '1'],
            ]])->fresh();
        $tasks[] = $task;
        $mapping[$task->key] = ['process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping));
    $store->activate($release->id, null);
    $this->processes = collect(app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne'))->keyBy('definition_id');
    $this->details = ['label' => 'Private synthetic identity paper', 'kind' => 'synthetic.identity', 'reported_available' => true, 'expires_on' => '2027-01-01'];
});

test('evidence availability and confirmation are independent for every process', function () {
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    expect(DB::table('bureaucracy_evidence_items')->where('id', $evidence->id)->value('details'))->not->toContain('Private synthetic');
    $paperwork = app(PaperworkReadModel::class);
    $rows = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    $one = $rows['fixture.one.document.identity'];
    $request = (string) Str::uuid();
    $confirm = app(ConfirmRequirementUse::class);
    $use = $confirm->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], $request);
    expect($confirm->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], $request)->id)->toBe($use->id);
    $result = $paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $rows = collect($result['requirements'])->keyBy('id');
    expect($rows[$one['id']]['readiness'])->toBe('confirmed_for_use')
        ->and($rows['fixture.two.document.identity']['readiness'])->toBe('reported_available')
        ->and($result['evidence'])->toHaveCount(1)->and($result['capabilities']['translation']['available'])->toBeFalse();
    app(RecordProcessEvent::class)->execute($this->actor, $this->processes['fixture.two'], 'step_completed', ['step_id' => 'fixture.two.complete'], 1, (string) Str::uuid());
    $rows = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    expect($rows['fixture.two.document.identity']['readiness'])->toBe('reported_available');
});

test('changing or expiring evidence requires confirmation again without deleting the previous report', function () {
    $records = app(RecordEvidence::class);
    $evidence = $records->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    $paperwork = app(PaperworkReadModel::class);
    $one = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', 'fixture.one.document.identity');
    app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], (string) Str::uuid());
    $records->execute($this->actor, $this->case->person, $evidence->id, [...$this->details, 'expires_on' => '2026-01-01'], 1, (string) Str::uuid());
    $rows = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    expect($rows[$one['id']]['readiness'])->toBe('needs_reconfirmation');
    expect(DB::table('bureaucracy_requirement_uses')->count())->toBe(1);
    expect(fn () => app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 2, 1, $one['semantic_hash'], (string) Str::uuid()))
        ->toThrow(ConflictHttpException::class);
});

test('finishing a task never creates evidence and a stale requirement cannot be confirmed', function () {
    $paperwork = app(PaperworkReadModel::class);
    app(RecordProcessEvent::class)->execute($this->actor, $this->processes['fixture.one'], 'step_completed', ['step_id' => 'fixture.one.complete'], 1, (string) Str::uuid());
    $result = $paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($result['evidence'])->toBe([])->and(collect($result['requirements'])->firstWhere('id', 'fixture.one.document.identity')['readiness'])->toBe('missing');
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    expect(fn () => app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], 'fixture.one.document.identity', $evidence->id, 2, 1, str_repeat('0', 64), (string) Str::uuid()))
        ->toThrow(ConflictHttpException::class);
});

test('a mistaken use can be withdrawn without erasing the document or its confirmation history', function () {
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    $paperwork = app(PaperworkReadModel::class);
    $one = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', 'fixture.one.document.identity');
    app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], (string) Str::uuid());
    $withdraw = app(WithdrawRequirementUse::class);
    $request = (string) Str::uuid();
    $receipt = $withdraw->execute($this->actor, $this->processes['fixture.one'], $one['id'], 2, $request);
    expect($withdraw->execute($this->actor, $this->processes['fixture.one'], $one['id'], 2, $request)->id)->toBe($receipt->id);
    $rows = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    expect($rows[$one['id']]['readiness'])->toBe('reported_available')->and(BureaucracyEvidenceItem::query()->find($evidence->id))->not->toBeNull()
        ->and(DB::table('bureaucracy_requirement_uses')->count())->toBe(2);
});

test('passing the expiry date invalidates readiness without any background write', function () {
    $this->travelTo(now()->setDate(2026, 9, 8)->startOfDay());
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), [...$this->details, 'expires_on' => '2026-09-08'], 0, (string) Str::uuid());
    $paperwork = app(PaperworkReadModel::class);
    $one = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', 'fixture.one.document.identity');
    app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], (string) Str::uuid());
    $this->travel(1)->days();
    expect(collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', $one['id'])['readiness'])->toBe('needs_reconfirmation');
    expect(DB::table('bureaucracy_requirement_uses')->count())->toBe(1);
});

test('a shared document needs owner permission for the exact process and revocation takes effect on read', function () {
    $other = User::factory()->create();
    $otherCase = app(EnsureAccountHolder::class)->dossier($other);
    $sharing = app(ManageDelegation::class);
    $invitation = $sharing->invite($this->actor, $this->case->person->workspace, $other->email, ['view_plan', 'manage_process', 'manage_evidence']);
    $sharing->accept($other, $invitation['token'], ['view_plan', 'manage_process', 'manage_evidence']);
    $otherProcesses = collect(app(ReconcileProcesses::class)->execute($this->actor, $otherCase->person, 'de-nrw-cologne'))->keyBy('definition_id');
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    $paperwork = app(PaperworkReadModel::class);
    $rows = collect($paperwork->for($other, $otherCase->person, 'de-nrw-cologne')['requirements'])->keyBy('id');
    $one = $rows['fixture.one.document.identity'];
    expect(fn () => app(ConfirmRequirementUse::class)->execute($this->actor, $otherProcesses['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    $shareService = app(ShareEvidence::class);
    $shareUrl = '/bureaucracy/v2/evidence/'.$evidence->id.'/shares';
    $notice = $this->actingAs($this->actor)->getJson($shareUrl)->assertSuccessful()->json('notice_version');
    $shareId = $this->postJson($shareUrl, ['process_id' => $otherProcesses['fixture.one']->id, 'requirement_id' => $one['id'],
        'evidence_version' => 1, 'requirement_hash' => $one['semantic_hash'], 'expires_on' => now()->addMonth()->toDateString(),
        'request_id' => (string) Str::uuid(), 'notice_version' => $notice, 'confirmed' => true])->assertSuccessful()->json('share_id');
    $this->actingAs($other)->getJson($shareUrl)->assertForbidden();
    $received = $paperwork->for($other, $otherCase->person, 'de-nrw-cologne');
    $receivedItem = collect($received['evidence'])->firstWhere('id', $evidence->id);
    expect($receivedItem)->not->toBeNull()
        ->and(collect($received['requirements'])->firstWhere('id', $one['id'])['suggested_evidence_ids'])->toBe([$evidence->id]);
    app(ConfirmRequirementUse::class)->execute($other, $otherProcesses['fixture.one'], $one['id'], $evidence->id, 1, 1, $one['semantic_hash'], (string) Str::uuid());
    $result = $paperwork->for($other, $otherCase->person, 'de-nrw-cologne');
    expect($result['evidence'])->toHaveCount(1)->and(collect($result['requirements'])->firstWhere('id', $one['id'])['readiness'])->toBe('confirmed_for_use');
    $two = $rows['fixture.two.document.identity'];
    expect(fn () => app(ConfirmRequirementUse::class)->execute($other, $otherProcesses['fixture.two'], $two['id'], $evidence->id, 1, 1, $two['semantic_hash'], (string) Str::uuid()))
        ->toThrow(AuthorizationException::class);
    $this->actingAs($this->actor)->deleteJson($shareUrl.'/'.$shareId)->assertNoContent();
    $result = $paperwork->for($other, $otherCase->person, 'de-nrw-cologne');
    expect($result['evidence'])->toBe([])->and(collect($result['requirements'])->firstWhere('id', $one['id'])['readiness'])->toBe('needs_reconfirmation');
    $shareService->execute($this->actor, BureaucracyEvidenceItem::query()->findOrFail($evidence->id), $otherProcesses['fixture.one'], $one['id'], 1, $one['semantic_hash'], now()->addMonth()->toDateString(), (string) Str::uuid());
    expect(collect($paperwork->for($other, $otherCase->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', $one['id'])['readiness'])->toBe('needs_reconfirmation');
});

test('retrying an old evidence write returns its original version and cannot overwrite a newer unseen edit', function () {
    $records = app(RecordEvidence::class);
    $id = (string) Str::uuid();
    $firstRequest = (string) Str::uuid();
    $records->execute($this->actor, $this->case->person, $id, $this->details, 0, $firstRequest);
    $newer = [...$this->details, 'label' => 'Newer private label'];
    $records->execute($this->actor, $this->case->person, $id, $newer, 1, (string) Str::uuid());
    $replay = $records->execute($this->actor, $this->case->person, $id, $this->details, 0, $firstRequest);
    expect($replay->version)->toBe(1);
    expect(fn () => $records->execute($this->actor, $this->case->person, $id, [...$this->details, 'label' => 'Stale edit'], $replay->version, (string) Str::uuid()))
        ->toThrow(ConflictHttpException::class);
});

test('evidence and its history are exported and removed with the subject dossier', function () {
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    $lifecycle = app(PersonDataLifecycle::class);
    expect($lifecycle->export($this->actor, $this->case->person)['evidence'][0]['details'])->toBe($this->details);
    $lifecycle->erase($this->actor, $this->case->person);
    expect(BureaucracyEvidenceItem::query()->find($evidence->id))->toBeNull()->and(DB::table('bureaucracy_evidence_events')->count())->toBe(0);
});

test('the paperwork API keeps evidence edits and use confirmations explicit and private', function () {
    $url = '/bureaucracy/v2/people/'.$this->case->person_id;
    $evidenceId = (string) Str::uuid();
    $request = ['request_id' => (string) Str::uuid(), 'expected_version' => 0, 'details' => $this->details];
    $this->actingAs($this->actor)->putJson($url.'/evidence/'.$evidenceId, $request)->assertSuccessful()->assertJsonPath('id', $evidenceId);
    $result = $this->getJson($url.'/paperwork?jurisdiction=de-nrw-cologne')->assertSuccessful();
    expect($result->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    $one = collect($result->json('requirements'))->firstWhere('id', 'fixture.one.document.identity');
    $confirm = ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'evidence_id' => $evidenceId,
        'evidence_version' => 1, 'requirement_hash' => $one['semantic_hash'], 'confirmed' => true];
    $processUrl = '/bureaucracy/v2/processes/'.$this->processes['fixture.one']->id.'/requirements/'.$one['id'];
    $this->postJson($processUrl.'/confirm', $confirm)->assertSuccessful();
    $this->deleteJson($processUrl.'/confirmation', ['request_id' => (string) Str::uuid(), 'expected_version' => 2])->assertSuccessful();
    $this->put($url.'/evidence/'.$evidenceId, [...$request, 'details' => ['label' => 'sensitive invalid payload']], ['Accept' => 'text/html'])
        ->assertUnprocessable()->assertSessionMissing('_old_input');
    $this->actingAs(User::factory()->create())->getJson($url.'/paperwork?jurisdiction=de-nrw-cologne')->assertForbidden();
    $this->putJson($url.'/evidence/'.$evidenceId, [...$request, 'expected_version' => 1])->assertForbidden();
});

test('copy-only catalogue changes preserve use but changed requirement semantics invalidate it', function () {
    $evidence = app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), $this->details, 0, (string) Str::uuid());
    $paperwork = app(PaperworkReadModel::class);
    $row = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', 'fixture.one.document.identity');
    app(ConfirmRequirementUse::class)->execute($this->actor, $this->processes['fixture.one'], $row['id'], $evidence->id, 1, 1, $row['semantic_hash'], (string) Str::uuid());
    $store = app(CatalogueReleaseStore::class);
    foreach (['copy', 'meaning'] as $change) {
        $before = $store->current()['release_hash'];
        $task = Task::query()->where('key', 'fixture.one')->sole();
        $document = [...$task->documents_required[0], 'label' => 'Clearer synthetic wording'];
        if ($change === 'meaning') {
            $document['requirement_version'] = '2';
        }
        $task->update(['documents_required' => [$document], 'content_version' => 'synthetic.'.$change]);
        $tasks = Task::query()->whereIn('key', ['fixture.one', 'fixture.two'])->orderBy('key')->get();
        $map = $tasks->mapWithKeys(fn ($unit) => [$unit->key => ['process_id' => $unit->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial']])->all();
        $release = $store->stage(app(CatalogueCompiler::class)->compile($tasks->all(), $map));
        $store->activate($release->id, $before);
        $current = collect($paperwork->for($this->actor, $this->case->person, 'de-nrw-cologne')['requirements'])->firstWhere('id', $row['id']);
        expect($current['readiness'])->toBe($change === 'copy' ? 'confirmed_for_use' : 'needs_reconfirmation');
    }
});
