<?php

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\Task;
use App\Models\User;

/**
 * Found by walking realistic people through the plan: information cards for another route,
 * after-arrival steps for someone still planning, and questions a visa-free arrival could not
 * answer truthfully.
 */
beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $card = fn (string $key, string $type, string $phase, array $appliesIf) => Task::factory()->approvedFixture()->create([
        'key' => $key, 'title' => 'Synthetic '.$key, 'type' => $type, 'phase' => $phase, 'deadline_type' => 'none',
        'applies_if' => $appliesIf, 'depends_on' => [], 'documents_required' => [], 'how_to_steps' => [],
        'links' => ['https://www.stadt-koeln.de/service/produkte/00415/index.html']])->fresh();
    $units = [
        // Another route's information card: only for an Opportunity Card track.
        [$card('fixture.applies.track_info', 'info', 'ongoing', [['citizenship_group' => 'non_eu', 'permit_track' => 'chancenkarte']]), 'context'],
        // A settlement option for Blue Card holders, which needs months of employment.
        [$card('fixture.applies.settlement', 'info', 'options', [['current_residence_title' => 'blue_card', 'blue_card_qualifying_months' => ['in' => [12, 13, 14]]]]), 'option'],
        // Something to do around arrival, and something only after it.
        [$card('fixture.applies.insurance', 'task', 'arrival', [['purpose' => 'employment']]), 'preparation'],
        [$card('fixture.applies.tax_return', 'task', 'ongoing', [['purpose' => 'employment', 'tax_id_available' => false]]), 'action'],
    ];
    $tasks = array_column($units, 0);
    $mapping = [];
    foreach ($units as [$task, $kind]) {
        $mapping[$task->key] = ['process_id' => $task->key, 'position' => 1, 'topic' => 'residence', 'kind' => $kind, 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping))->id, null);
    $this->record = function (array $facts): void {
        foreach ($facts as $key => $value) {
            app(RecordFactChange::class)->execute($this->actor, $this->case->person->fresh(), $key, $value, null, $this->case->fresh()->fact_version);
        }
    };
    $this->plan = fn () => app(PlanReadModel::class)->for($this->actor, $this->case->person->fresh(), 'de-nrw-cologne');
    $this->row = fn (string $key) => collect(($this->plan)()['guidance'])->firstWhere('id', $key);
    $this->questions = fn () => array_column(app(QuestionProtocol::class)->candidates(
        app(PrepareAssessmentInput::class)->for($this->actor, $this->case->person->fresh(), 'de-nrw-cologne')), 'fact_key');
});

test('an information card applies only when every condition is met, not while one is unanswered', function () {
    ($this->record)(['citizenship_group' => 'non_eu', 'purpose' => 'employment', 'arrival_planned' => false]);
    expect(($this->row)('fixture.applies.track_info')['applies'])->toBeFalse();

    ($this->record)(['permit_track' => 'chancenkarte']);
    expect(($this->row)('fixture.applies.track_info')['applies'])->toBeTrue();
});

test('an option that fits is marked as applying, though it is never a task', function () {
    ($this->record)(['citizenship_group' => 'non_eu', 'purpose' => 'employment', 'arrival_planned' => false,
        'current_residence_title' => 'blue_card', 'blue_card_qualifying_months' => 13]);
    $option = ($this->row)('fixture.applies.settlement');

    expect($option)->toMatchArray(['kind' => 'option', 'applies' => true, 'actionable' => false])
        ->and(array_column(($this->plan)()['processes'], 'definition_id'))->not->toContain('fixture.applies.settlement');
});

test('someone still planning the move gets arrival steps; later steps wait and ask nothing', function () {
    ($this->record)(['citizenship_group' => 'non_eu', 'purpose' => 'employment', 'arrival_planned' => true]);

    expect(($this->row)('fixture.applies.insurance'))->toMatchArray(['actionable' => true, 'after_arrival' => false])
        ->and(($this->row)('fixture.applies.tax_return')['actionable'])->toBeFalse()
        ->and(($this->questions)())->not->toContain('tax_id_available');

    ($this->record)(['tax_id_available' => false]);
    expect(($this->row)('fixture.applies.tax_return'))->toMatchArray(['actionable' => false, 'after_arrival' => true]);

    ($this->record)(['arrival_planned' => false]);
    expect(($this->row)('fixture.applies.tax_return'))->toMatchArray(['actionable' => true, 'after_arrival' => false]);
});

test('no residence title yet is an answer, and settles the questions that need a held title', function () {
    ($this->record)(['citizenship_group' => 'non_eu', 'purpose' => 'employment', 'arrival_planned' => false]);
    expect(($this->questions)())->toContain('current_residence_title');

    ($this->record)(['current_residence_title' => 'none']);
    expect(($this->questions)())->not->toContain('current_residence_title')->not->toContain('blue_card_qualifying_months');
});
