<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\OpenTaskCount;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Processes\ReconcileProcesses;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Home\HomeContext;
use App\Home\TileComposer;
use App\Models\BureaucracyPerson;
use App\Models\Task;
use App\Models\User;
use App\Profile\ProfileEngine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->onboarded()->create(['city' => 'Köln']);
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'entry_mode', 'd_visa', null, 1);
    app(RecordFactChange::class)->execute($this->actor, $this->case->person, 'visa_expires_at', now()->addDays(2)->toDateString(), null, 2);
    $this->task = Task::factory()->approvedFixture()->create(['key' => 'fixture.consumer', 'title' => 'Synthetic preparation',
        'type' => 'task', 'applies_if' => [['entry_mode' => 'd_visa']], 'depends_on' => [], 'deadline_type' => 'fact_date',
        'deadline_fact_key' => 'visa_expires_at', 'documents_required' => [], 'how_to_steps' => [], 'links' => []])->fresh();
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage(app(CatalogueCompiler::class)->compile([$this->task], [$this->task->key => [
        'process_id' => 'fixture.consumer', 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial',
    ]]));
    $store->activate($release->id, null);
});

test('the account adapter sidebar and Today consume the same reviewed plan without a legacy task row', function () {
    $plan = app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
    expect(app(AccountHolderPlan::class)->for($this->actor)['assessment_revision'])->toBe($plan['assessment_revision']);
    $attention = app(PlanAttention::class)->for($plan);
    expect($attention)->toHaveCount(1)->and($attention[0]['kind'])->toBe('preparation_target')
        ->and($attention[0]['action']['type'])->toBe('open_process')->and(app(OpenTaskCount::class)->forUser($this->actor))->toBe(1);
    $context = new HomeContext(userId: $this->actor->id, profile: app(ProfileEngine::class)->build($this->actor),
        now: CarbonImmutable::now(), rainExpected: false, rainSummary: null, intentWeights: [], isWeekendWindow: false,
        isEvening: false, openTasks: collect(), tonightEvents: collect(), bureaucracyPlan: $plan);
    $tile = collect(app(TileComposer::class)->tiles($context))->firstWhere('type', 'bureaucracy_deadline');
    expect($tile['meta']['assessment_revision'])->toBe($plan['assessment_revision'])
        ->and($tile['meta']['temporal_kind'])->toBe('preparation_target')->and($tile['meta']['action']['type'])->toBe('open_process');
    expect($this->actor->userTasks()->count())->toBe(0);
});

test('a later appointment does not replace the preparation date or double-count the sidebar process', function () {
    $process = app(ReconcileProcesses::class)->execute($this->actor, $this->case->person, 'de-nrw-cologne')[0];
    app(RecordProcessEvent::class)->execute($this->actor, $process, 'appointment_recorded', [
        'appointment_id' => (string) Str::uuid(), 'starts_at' => now()->addDays(7)->toIso8601String(), 'timezone' => 'Europe/Berlin', 'duration_minutes' => 30,
    ], 1, (string) Str::uuid());
    $plan = app(AccountHolderPlan::class)->for($this->actor);
    $attention = app(PlanAttention::class)->for($plan);
    expect(array_column($attention, 'kind'))->toContain('appointment', 'preparation_target')
        ->and(collect($attention)->firstWhere('kind', 'preparation_target')['date'])->toBe(now()->addDays(2)->toDateString())
        ->and(app(OpenTaskCount::class)->forUser($this->actor))->toBe(1);
});

test('a missing account dossier never falls back to a family member or writes one while reading', function () {
    $actor = User::factory()->onboarded()->create();
    $before = BureaucracyPerson::query()->count();
    expect(app(AccountHolderPlan::class)->for($actor))->toBeNull()->and(app(OpenTaskCount::class)->forUser($actor))->toBe(0);
    expect(BureaucracyPerson::query()->count())->toBe($before);
});
