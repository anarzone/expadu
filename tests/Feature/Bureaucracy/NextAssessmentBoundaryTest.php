<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\ConfirmRequirementUse;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\Evidence\ShareEvidence;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\People\ManageDependents;
use App\Bureaucracy\People\RecordRelationship;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyEvidenceItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->activate = function (array $conditions = []) {
        $task = Task::factory()->approvedFixture()->create(['key' => 'fixture.boundary', 'type' => 'task', 'applies_if' => $conditions,
            'depends_on' => [], 'deadline_type' => 'none', 'links' => [], 'how_to_steps' => [], 'documents_required' => [
                ['id' => 'identity', 'label' => 'Synthetic paper', 'evidence_kind' => 'identity', 'requirement_version' => '1'],
            ]])->fresh();
        $store = app(CatalogueReleaseStore::class);
        $release = $store->stage(app(CatalogueCompiler::class)->compile([$task], [$task->key => [
            'process_id' => $task->key, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
        ]]));
        $store->activate($release->id, null);
    };
    $this->read = fn () => app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
});

test('a fact reconfirmation boundary preserves the existing application-local timestamp semantics', function () {
    ($this->activate)([['german_level' => 'b1']]);
    $fact = app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'german_level', 'b1', null, 1);
    $fact->update(['reconfirm_at' => now()->addHours(2)]);
    $plan = ($this->read)();
    expect(strtotime($plan['next_reassessment_at']))->toBe(now()->addHours(2)->timestamp)->and($plan['actions'])->toHaveCount(1);
    $this->travel(2)->hours();
    expect(($this->read)()['actions'])->toBeEmpty();
});

test('a helper grant expiry earlier than the local UTC offset remains a reassessment boundary', function () {
    ($this->activate)();
    $helper = User::factory()->create();
    $workspace = app(EnsureAccountHolder::class)->dossier($helper)->person->workspace;
    $invite = app(ManageDelegation::class)->invite($helper, $workspace, $this->actor->email, ['view_plan']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_plan']);
    BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole()->update(['expires_at' => now()->addHour()]);
    $plan = app(PlanReadModel::class)->for($helper, $this->case->person, 'de-nrw-cologne');
    expect(strtotime($plan['next_reassessment_at']))->toBe(now()->addHour()->timestamp);
    $this->travel(1)->hours();
    expect(fn () => app(PlanReadModel::class)->for($helper, $this->case->person, 'de-nrw-cologne'))->toThrow(AuthorizationException::class);
});

test('the applicant plan expires when a consumed sponsor fact needs reconfirmation', function () {
    ($this->activate)([['sponsor_current_title' => 'blue_card']]);
    $sponsor = User::factory()->create();
    $sponsorCase = app(EnsureAccountHolder::class)->dossier($sponsor);
    $invite = app(ManageDelegation::class)->invite($this->actor, $this->case->person->workspace, $sponsor->email, ['view_facts']);
    app(ManageDelegation::class)->accept($sponsor, $invite['token'], ['view_facts']);
    app(RecordRelationship::class)->execute($this->actor, $this->case->person, $sponsorCase->person, 'sponsor', null, $this->case->person->fresh()->record_version);
    $fact = app(RecordFactChange::class)->execute($sponsor, $sponsorCase->person, 'current_residence_title', 'blue_card', null, 1);
    $fact->update(['reconfirm_at' => now()->addHour()]);
    $plan = ($this->read)();
    expect(strtotime($plan['next_reassessment_at']))->toBe(now()->addHour()->timestamp)->and($plan['actions'])->toHaveCount(1);
    $this->travel(1)->hours();
    expect(($this->read)()['actions'])->toBeEmpty();
});

test('shared evidence uses the earlier of the share and the source guardians authority expiry', function () {
    ($this->activate)();
    config()->set('bureaucracy_family.guardian_policy_version', 'synthetic.boundary.1');
    $guardian = User::factory()->create();
    $authority = app(ManageDependents::class)->request($guardian, 'Synthetic dependent');
    app(ManageDependents::class)->review(User::factory()->create(['is_admin' => true]), $authority, 'synthetic.boundary.1', 'Synthetic reviewed evidence', now()->addHours(3));
    $child = $authority->person;
    $invite = app(ManageDelegation::class)->invite($guardian, $child->workspace, $this->actor->email, ['view_plan']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_plan']);
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    $evidence = app(RecordEvidence::class)->execute($guardian, $child, (string) Str::uuid(), [
        'label' => 'Synthetic shared paper', 'kind' => 'identity', 'reported_available' => true, 'expires_on' => null,
    ], 0, (string) Str::uuid());
    $requirement = ($this->read)()['paperwork']['requirements'][0];
    $share = app(ShareEvidence::class)->execute($guardian, BureaucracyEvidenceItem::query()->findOrFail($evidence->id), $process,
        $requirement['id'], 1, $requirement['semantic_hash'], now()->addDay()->toDateString(), (string) Str::uuid());
    app(ConfirmRequirementUse::class)->execute($this->actor, $process, $requirement['id'], $evidence->id, 1, 1, $requirement['semantic_hash'], (string) Str::uuid());
    expect(strtotime(($this->read)()['next_reassessment_at']))->toBe(now()->addHours(3)->timestamp);
    $share->update(['expires_at' => now()->addHours(2)]);
    expect(strtotime(($this->read)()['next_reassessment_at']))->toBe(now()->addHours(2)->timestamp);
    $this->travel(2)->hours();
    expect(($this->read)()['paperwork']['requirements'][0]['readiness'])->toBe('needs_reconfirmation');
});

test('an upcoming appointment triggers reassessment when its reminder window ends', function () {
    ($this->activate)();
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'appointment_recorded', [
        'appointment_id' => (string) Str::uuid(), 'starts_at' => now()->addHour()->toIso8601String(), 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
    ], 1, (string) Str::uuid());
    expect(strtotime(($this->read)()['next_reassessment_at']))->toBe(now()->addHour()->timestamp);
    $this->travel(1)->hours();
    expect(app(PlanAttention::class)->for(($this->read)()))->toBeEmpty();
});
