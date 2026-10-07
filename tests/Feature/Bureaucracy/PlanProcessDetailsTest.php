<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyOutboxEvent;
use App\Models\BureaucracyProcess;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'entry_mode', 'd_visa', null, 1);
    // Alphabetical order (alpha before zeta) deliberately contradicts the reviewed catalogue order.
    $prepare = Task::factory()->approvedFixture()->create(['key' => 'fixture.flow.zeta-prepare', 'title' => 'Synthetic prepare step',
        'description' => 'Synthetic preparation text.', 'type' => 'task', 'applies_if' => [['entry_mode' => 'd_visa']], 'depends_on' => [],
        'deadline_type' => 'none', 'how_to_steps' => [], 'links' => ['https://www.stadt-koeln.de/service/produkte/00415/index.html'],
        'documents_required' => [['id' => 'passport', 'label' => 'Synthetic passport', 'requirement_version' => '1', 'evidence_kind' => 'passport'],
            ['id' => 'contract', 'label' => 'Synthetic contract', 'requirement_version' => '1', 'evidence_kind' => 'contract']]])->fresh();
    $submit = Task::factory()->approvedFixture()->create(['key' => 'fixture.flow.alpha-submit', 'title' => 'Synthetic submit step',
        'type' => 'task', 'applies_if' => [['entry_mode' => 'd_visa']], 'depends_on' => ['fixture.flow.zeta-prepare'],
        'deadline_type' => 'fact_date', 'deadline_fact_key' => 'visa_expires_at', 'how_to_steps' => [], 'links' => [], 'documents_required' => []])->fresh();
    $this->units = [$prepare, $submit];
    $this->mapping = [
        'fixture.flow.zeta-prepare' => ['process_id' => 'fixture.flow', 'position' => 1, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'],
        'fixture.flow.alpha-submit' => ['process_id' => 'fixture.flow', 'position' => 2, 'topic' => 'residence', 'kind' => 'action', 'coverage' => 'partial'],
    ];
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile($this->units, $this->mapping));
    $store->activate($release->id, null);
    $this->read = fn () => app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
});

test('a proposal carries its reviewed title, topic label and steps in catalogue order with typed dates and paperwork counts', function () {
    $before = [BureaucracyProcess::query()->count(), BureaucracyOutboxEvent::query()->count()];
    $plan = ($this->read)();
    $process = $plan['processes'][0];
    expect($process['title'])->toBe('Synthetic prepare step')->and($process['topic_label'])->toBe('Residence')
        ->and($plan['topics'][0]['label'])->toBe('Residence')->and($process['is_closed'])->toBeFalse()->and($process['closed_on'])->toBeNull()
        ->and(array_column($process['steps'], 'step_id'))->toBe(['fixture.flow.zeta-prepare.complete', 'fixture.flow.alpha-submit.complete'])
        ->and(array_column($process['steps'], 'position'))->toBe([1, 2])
        ->and(array_column($process['steps'], 'status'))->toBe(['todo', 'blocked']);
    $prepare = $process['steps'][0];
    expect($prepare['id'])->toBe($process['occurrence_key'].':fixture.flow.zeta-prepare.complete')
        ->and($prepare['description'])->toBe('Synthetic preparation text.')
        ->and($prepare['verified_at'])->toBe(today()->toDateString())->and($prepare['content_version'])->toBe('synthetic-fixture.1')
        ->and(array_column($prepare['sources']['official'], 'url'))->toBe(['https://www.stadt-koeln.de/service/produkte/00415/index.html'])
        ->and(array_column($prepare['sources']['legal'], 'url'))->toContain('https://www.gesetze-im-internet.de/bmg/__17.html')
        ->and($prepare['requirements'])->toBe(['ready' => 0, 'total' => 2])
        ->and($prepare['first_open_requirement'])->toMatchArray(['label' => 'Synthetic passport', 'readiness' => 'missing', 'conditional' => false]);
    $submit = $process['steps'][1];
    expect($submit['depends_on'])->toBe(['fixture.flow.zeta-prepare.complete'])->and($submit['dates'])->toHaveCount(1)
        ->and($submit['dates'][0])->toMatchArray(['state' => 'date_unknown', 'needed_fact' => 'visa_expires_at', 'date' => null, 'action_id' => $submit['id']])
        ->and($plan['history_count'])->toBe(0);
    expect([BureaucracyProcess::query()->count(), BureaucracyOutboxEvent::query()->count()])->toBe($before);
});

test('each action date row names the action it applies to and the step it came from', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'visa_expires_at', now()->addDay()->toDateString(), null, 2);
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'step_completed', ['step_id' => 'fixture.flow.zeta-prepare.complete'], 1, (string) Str::uuid());
    $plan = ($this->read)();
    $action = collect($plan['actions'])->firstWhere('step_id', 'fixture.flow.alpha-submit.complete');
    expect($action['id'])->toBe($process->id.':fixture.flow.alpha-submit.complete')->and($action['process_title'])->toBe('Synthetic prepare step')
        ->and($action['dates'])->toHaveCount(1)
        ->and($action['dates'][0])->toMatchArray(['action_id' => $action['id'], 'step_id' => 'fixture.flow.alpha-submit.complete',
            'date' => now()->addDay()->toDateString(), 'state' => 'dated']);
    $step = collect($plan['processes'][0]['steps'])->firstWhere('step_id', 'fixture.flow.alpha-submit.complete');
    expect($step['dates'])->toBe($action['dates'])->and($step['status'])->toBe('todo');
});

test('closed processes report their own closing date, leave next actions and count as history', function () {
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'cancellation_reported', ['occurred_on' => now()->subDay()->toDateString()], 1, (string) Str::uuid());
    $plan = ($this->read)();
    $closed = collect($plan['processes'])->firstWhere('id', $process->id);
    expect($closed['is_closed'])->toBeTrue()->and($closed['closed_on'])->toBe(now()->subDay()->toDateString())
        ->and($closed['closed_event_id'])->toBeInt()->and(array_column($closed['steps'], 'status'))->toBe(['cancelled', 'cancelled'])
        ->and(collect($plan['actions'])->where('process_id', $process->id))->toBeEmpty()
        ->and($plan['history_count'])->toBe(1);
});

test('a process needing action names the first not-ready requirement from the person’s own paperwork only', function () {
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    expect(($this->read)()['processes'][0]['blocking_reason'])->toBeNull();
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'preparation_started', [], 1, (string) Str::uuid());
    app(RecordProcessEvent::class)->execute($this->actor, $process->fresh(), 'action_required_reported', [], 2, (string) Str::uuid());
    $reason = ($this->read)()['processes'][0]['blocking_reason'];
    expect($reason)->toBe(['basis' => 'requirement_readiness', 'step_id' => 'fixture.flow.zeta-prepare.complete',
        'requirement_id' => 'fixture.flow.zeta-prepare.document.passport', 'label' => 'Synthetic passport', 'readiness' => 'missing', 'applicability' => 'required']);
});

test('reviewed step positions must be positive and unique within a process', function (array $positions) {
    $mapping = $this->mapping;
    $mapping['fixture.flow.zeta-prepare']['position'] = $positions[0];
    $mapping['fixture.flow.alpha-submit']['position'] = $positions[1];
    expect(fn () => app(CatalogueCompiler::class)->compile($this->units, $mapping))->toThrow(DomainException::class);
})->with([
    'duplicate' => [[1, 1]],
    'zero' => [[0, 2]],
    'not an integer' => [['1', 2]],
]);

test('a recorded D-visa expiry is its own personal date row and is linked from the action deadline it anchors', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'current_residence_title', 'national_d_visa', null, 2);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'visa_expires_at', now()->addDay()->toDateString(), null, 3);
    $plan = ($this->read)();
    $expiry = collect($plan['timeline'])->firstWhere('kind', 'document_expiry');
    expect($expiry)->toMatchArray(['id' => 'visa.expiry', 'fact_key' => 'visa_expires_at', 'document' => 'visa', 'date' => now()->addDay()->toDateString(),
        'legal_effect' => 'not_assessed', 'provenance' => 'confirmed_fact']);
    $action = collect($plan['actions'])->firstWhere('step_id', 'fixture.flow.alpha-submit.complete')
        ?? collect($plan['processes'][0]['steps'])->firstWhere('step_id', 'fixture.flow.alpha-submit.complete');
    expect($action['dates'][0])->toMatchArray(['anchor_fact' => 'visa_expires_at', 'anchor_event_id' => 'visa.expiry', 'action_id' => $action['id']]);
    $attention = collect($plan['attention']);
    expect($attention->firstWhere('kind', 'document_expiry'))->toMatchArray(['id' => 'visa.expiry', 'days_remaining' => 1, 'legal_effect' => 'not_assessed', 'state' => 'dated'])
        ->and($attention->firstWhere('action_id', $action['id']))->toMatchArray(['kind' => 'preparation_target', 'days_remaining' => 1, 'anchor_event_id' => 'visa.expiry'])
        ->and(array_column($plan['coming_up'], 'id'))->toEqualCanonicalizing(array_column($plan['attention'], 'id'));
});

test('coming up keeps only the next seven days and unknown dates stay unknown in attention', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'current_residence_title', 'national_d_visa', null, 2);
    $undated = ($this->read)();
    $row = collect($undated['attention'])->firstWhere('kind', 'preparation_target');
    expect($row)->toMatchArray(['date' => null, 'days_remaining' => null, 'urgency' => 'date_unknown', 'state' => 'date_unknown', 'needed_fact' => 'visa_expires_at'])
        ->and($undated['coming_up'])->toBeEmpty();
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'visa_expires_at', now()->addDays(10)->toDateString(), null, 3);
    $later = ($this->read)();
    expect(collect($later['attention'])->pluck('days_remaining')->unique()->values()->all())->toBe([10])->and($later['coming_up'])->toBeEmpty();
});

test('recorded title expiry dates are not shown for an unknown or settlement title', function (?string $title, string $fact, array $expected) {
    $revision = 2;
    if ($title !== null) {
        app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'current_residence_title', $title, null, $revision++);
    }
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, $fact, now()->addMonths(3)->toDateString(), null, $revision);
    $rows = collect(($this->read)()['timeline'])->where('kind', 'document_expiry')->pluck('id')->values()->all();
    expect($rows)->toBe($expected);
})->with([
    'visa with unknown title' => [null, 'visa_expires_at', []],
    'visa after a later title' => ['blue_card', 'visa_expires_at', []],
    'limited residence title' => ['blue_card', 'residence_title_expires_at', ['residence-title.expiry']],
    'settlement title' => ['settlement_permit_9', 'residence_title_expires_at', []],
    'unknown residence title' => [null, 'residence_title_expires_at', []],
]);
