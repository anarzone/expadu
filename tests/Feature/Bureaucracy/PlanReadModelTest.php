<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Evidence\PaperworkReadModel;
use App\Bureaucracy\Evidence\RecordEvidence;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\People\ManageDelegation;
use App\Bureaucracy\Processes\ReadProcess;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Questions\AnswerQuestion;
use App\Bureaucracy\Questions\DeferQuestion;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $mapping = [];
    $this->tasks = [];
    foreach (['address', 'residence', 'health', 'tax', 'money'] as $topic) {
        $key = 'fixture.plan.'.$topic;
        $this->tasks[] = Task::factory()->approvedFixture()->create(['key' => $key, 'title' => 'Synthetic '.$topic.' preparation',
            'type' => 'task', 'deadline_type' => 'none', 'applies_if' => [], 'depends_on' => [],
            'documents_required' => [['id' => 'identity', 'label' => 'Synthetic identity document', 'evidence_kind' => 'identity', 'requirement_version' => '1']],
            'how_to_steps' => [], 'links' => []])->fresh();
        $mapping[$key] = ['process_id' => $key, 'topic' => $topic, 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile($this->tasks, $mapping));
    $store->activate($release->id, null);
});

test('the shared plan is bounded on overview but retains every actionable process without GET side effects', function () {
    $before = BureaucracyOutboxEvent::query()->count();
    $one = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $two = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($one)->toBe($two)->and($one['schema_version'])->toBe('bureaucracy.plan.1')
        ->and($one['overview']['next_actions'])->toHaveCount(3)->and($one['processes'])->toHaveCount(5)
        ->and($one['topics'])->toHaveCount(5)->and($one['progress']['total'])->toBe(5)
        ->and($one['progress']['todo']['ids'])->toHaveCount(5)->and($one['overview']['question'])->not->toBeNull();
    expect(BureaucracyProcess::query()->count())->toBe(0)->and(BureaucracyCaseQuestion::query()->count())->toBe(0)
        ->and(BureaucracyOutboxEvent::query()->count())->toBe($before);
    expect(app(PaperworkReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne'))->toBe($one['paperwork']);
    $this->actingAs($this->actor)->getJson('/bureaucracy/v2/people/'.$this->case->person_id.'/plan?jurisdiction=de-nrw-cologne')
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('assessment_revision', $one['assessment_revision']);
});

test('withdrawn guidance disappears from a repeated read and progress without creating replacement state', function () {
    $one = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $this->tasks[0]->update(['is_published' => false]);
    $two = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($one['assessment_revision'])->not->toBe($two['assessment_revision'])->and($two['progress']['total'])->toBe(4)
        ->and(array_column($two['processes'], 'definition_id'))->not->toContain($this->tasks[0]->key)
        ->and($two['coverage']['state'])->toBe('partial');
});

test('workflow appointments evidence and midnight boundaries all change the same plan revision', function () {
    $read = fn () => app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $one = $read();
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'appointment_recorded', [
        'appointment_id' => (string) Str::uuid(), 'starts_at' => now()->addDay()->toIso8601String(), 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
    ], 1, (string) Str::uuid());
    $two = $read();
    expect($two['assessment_revision'])->not->toBe($one['assessment_revision'])->and(array_column($two['timeline'], 'kind'))->toContain('appointment');
    app(RecordEvidence::class)->execute($this->actor, $this->case->person, (string) Str::uuid(), [
        'label' => 'Synthetic document', 'kind' => 'identity', 'reported_available' => true, 'expires_on' => null,
    ], 0, (string) Str::uuid());
    $three = $read();
    expect($three['assessment_revision'])->not->toBe($two['assessment_revision']);
    $this->travel(1)->days();
    expect($read()['assessment_revision'])->not->toBe($three['assessment_revision']);
});

test('a plan-only helper sees no evidence inventory and revoked access cannot return a prior projection', function () {
    $helper = User::factory()->create();
    $workspace = app(EnsureAccountHolder::class)->dossier($helper)->person->workspace;
    $invite = app(ManageDelegation::class)->invite($helper, $workspace, $this->actor->email, ['view_plan']);
    app(ManageDelegation::class)->accept($this->actor, $invite['token'], ['view_plan']);
    $plan = app(PlanReadModel::class)->for($helper, $this->case->person, 'de-nrw-cologne');
    expect($plan['paperwork']['available'])->toBeFalse()->and($plan['paperwork'])->not->toHaveKey('evidence')
        ->and($plan['overview']['question'])->toBeNull();
    app(ManageDelegation::class)->revoke($this->actor, BureaucracyAccessGrant::query()->where('person_id', $this->case->person_id)->sole());
    expect(fn () => app(PlanReadModel::class)->for($helper, $this->case->person, 'de-nrw-cologne'))->toThrow(AuthorizationException::class);
});

test('changed confirmed answers and outside coverage produce explicit current states', function () {
    $one = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'arrival_planned', true, null, 1);
    $two = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($two['assessment_revision'])->not->toBe($one['assessment_revision']);
    $outside = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'outside_coverage');
    expect($outside['processes'])->toBeEmpty()->and($outside['coverage']['state'])->toBe('outside_coverage');
});

test('a few seconds passing alone does not invalidate the complete plan revision', function () {
    $one = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $this->travel(3)->seconds();
    $two = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($one['assessment_revision'])->toBe($two['assessment_revision']);
});

test('process detail and questionnaire preview reflect the same scoped plan and respect skips', function () {
    $session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($this->actor, $session, (string) Str::uuid())['question'];
    app(DeferQuestion::class)->execute($this->actor, $session, $offer['id'], $offer['token']);
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($plan['overview']['question']['fact_key'] ?? null)->not->toBe($offer['fact_key']);
    $detail = app(ReadProcess::class)->for($this->actor, $process);
    expect($detail['assessment_revision'])->toBe($plan['assessment_revision'])
        ->and($detail['state'])->toBe(collect($plan['processes'])->firstWhere('id', $process->id)['state']);
    $this->actingAs($this->actor)->getJson('/bureaucracy/v2/people/'.$this->case->person_id.'/question-preview?jurisdiction=de-nrw-cologne')
        ->assertOk()->assertJsonPath('assessment_revision', $plan['assessment_revision'])
        ->assertJsonPath('question.fact_key', $plan['overview']['question']['fact_key']);
});

test('reviewed description additions are appended and uncertain branches stay explicitly conditional', function () {
    $task = $this->tasks[0];
    $task->update(['description' => 'Synthetic base.', 'description_variants' => [
        ['applies_if' => ['arrival_planned' => true], 'body' => 'Synthetic planned move.'],
        ['applies_if' => [], 'body' => 'Synthetic common addition.'],
        ['applies_if' => ['livelihood_secured' => 'yes'], 'body' => 'Synthetic conditional addition.'],
        ['applies_if' => ['arrival_planned' => false], 'body' => 'Synthetic arrived addition.'],
    ]]);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$task->fresh()], [$task->key => [
        'process_id' => $task->key, 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, $store->current()['release_hash']);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'arrival_planned', true, null, 1);
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $guidance = $plan['guidance'][0];
    expect($guidance['description'])->toBe("Synthetic base.\n\nSynthetic planned move.\n\nSynthetic common addition.")
        ->and(array_column($guidance['description_additions'], 'body'))->not->toContain('Synthetic arrived addition.');
    expect(collect($guidance['description_additions'])->firstWhere('body', 'Synthetic conditional addition.')['applicability'])->toBe('conditional');
});

test('the preview and next command pause at the same interview boundary without GET writes', function () {
    $session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $answers = ['citizenship_group' => 'non_eu', 'arrival_planned' => false, 'current_residence_title' => 'blue_card'];
    for ($i = 0; $i < 3; $i++) {
        $offer = app(OfferNextQuestion::class)->execute($this->actor, $session, (string) Str::uuid())['question'];
        expect($answers)->toHaveKey($offer['fact_key']);
        // An already offered question remains answerable even when it is the third one.
        $preview = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
        expect($preview['questions']['status'])->toBe('offered');
        app(AnswerQuestion::class)->execute($this->actor, $session, $offer['id'], $offer['token'], $answers[$offer['fact_key']]);
    }
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect($plan['questions']['status'])->toBe('paused')->and($plan['questions']['can_resume'])->toBeTrue()
        ->and($plan['overview']['question'])->toBeNull()->and($session->fresh()->status)->toBe('active');
    expect(app(OfferNextQuestion::class)->execute($this->actor, $session, (string) Str::uuid())['status'])->toBe('paused');
});

test('the next reassessment boundary cannot outlast the current session or offer', function () {
    $this->travelTo(now()->startOfDay()->addHours(6));
    $session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($this->actor, $session, (string) Str::uuid())['question'];
    $session->update(['expires_at' => now()->addHours(6)]);
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect(strtotime($plan['next_reassessment_at']))->toBe(now()->addHours(6)->timestamp);
    BureaucracyCaseQuestion::query()->findOrFail($offer['id'])->update(['offer_expires_at' => now()->addHours(2)]);
    $earlier = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect(strtotime($earlier['next_reassessment_at']))->toBe(now()->addHours(2)->timestamp)
        ->and($earlier['assessment_revision'])->not->toBe($plan['assessment_revision']);
});

test('starting one proposal is explicit idempotent and does not create every suggested process', function () {
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    $proposal = $plan['processes'][0];
    $url = '/bureaucracy/v2/people/'.$this->case->person_id.'/processes';
    $request = ['jurisdiction' => 'de-nrw-cologne', 'occurrence_key' => $proposal['occurrence_key'],
        'review_token' => $proposal['review_token'], 'request_id' => (string) Str::uuid()];
    $receipt = $this->actingAs($this->actor)->postJson($url, $request)->assertSuccessful()
        ->assertHeader('Cache-Control', 'no-store, private')->json();
    expect(BureaucracyProcess::query()->count())->toBe(1);
    $this->postJson($url, $request)->assertSuccessful()->assertExactJson($receipt);
    $this->postJson($url, [...$request, 'occurrence_key' => $plan['processes'][1]['occurrence_key']])->assertConflict();
    $this->getJson('/bureaucracy/v2/processes/'.$receipt['process_id'])->assertOk()->assertJsonPath('state.workflow', 'not_started');
});

test('a changed proposal or missing management permission cannot start a process', function () {
    $proposal = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne')['processes'][0];
    $request = ['jurisdiction' => 'de-nrw-cologne', 'occurrence_key' => $proposal['occurrence_key'],
        'review_token' => $proposal['review_token'], 'request_id' => (string) Str::uuid()];
    $url = '/bureaucracy/v2/people/'.$this->case->person_id.'/processes';
    $this->actingAs(User::factory()->create())->postJson($url, $request)->assertForbidden();
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'arrival_planned', true, null, 1);
    $this->actingAs($this->actor)->postJson($url, $request)->assertConflict();
    expect(BureaucracyProcess::query()->count())->toBe(0);
});
