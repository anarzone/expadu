<?php

use App\Bureaucracy\Assessment\PrepareAssessmentInput;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Questions\QuestionProtocol;
use App\Models\Task;
use App\Models\User;

/**
 * Two equally urgent questions: one for work that already applies to this person (address
 * registration after moving in) and one that only decides whether other routes apply (the
 * employment track). The first is asked first, and the track is not asked at all once the
 * person chose the Blue Card as their goal.
 */
beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $units = [
        // More processes depend on the track, which used to win the tie.
        'fixture.order.work_permit' => [['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'standard']],
        'fixture.order.opportunity_card' => [['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'chancenkarte']],
        'fixture.order.registration' => [['arrival_planned' => false, 'registration_status' => 'not_registered']],
    ];
    $tasks = [];
    $mapping = [];
    foreach ($units as $key => $appliesIf) {
        $tasks[] = Task::factory()->approvedFixture()->create(['key' => $key, 'title' => 'Synthetic '.$key, 'type' => 'task', 'urgency' => 'critical',
            'deadline_type' => 'none', 'applies_if' => $appliesIf, 'depends_on' => [], 'documents_required' => [], 'how_to_steps' => [],
            'links' => ['https://www.stadt-koeln.de/service/produkte/00415/index.html']])->fresh();
        $mapping[$key] = ['process_id' => $key, 'position' => 1, 'topic' => 'residence', 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($tasks, $mapping))->id, null);
    foreach (['citizenship_group' => 'non_eu', 'purpose' => 'employment', 'arrival_planned' => false] as $key => $value) {
        app(RecordFactChange::class)->execute($this->actor, $this->case->person->fresh(), $key, $value, null, $this->case->fresh()->fact_version);
    }
    $this->questions = fn () => array_column(app(QuestionProtocol::class)->candidates(
        app(PrepareAssessmentInput::class)->for($this->actor, $this->case->person->fresh(), 'de-nrw-cologne')), 'fact_key');
});

test('a question for work that already applies comes before one that only decides other routes', function () {
    $order = ($this->questions)();

    expect($order)->toContain('registration_status')->toContain('permit_track')
        ->and(array_search('registration_status', $order, true))->toBeLessThan(array_search('permit_track', $order, true));
});

test('the employment track is not asked once the person chose the Blue Card', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person->fresh(), 'case_goal', 'blue_card', null, $this->case->fresh()->fact_version);

    expect(($this->questions)())->not->toContain('permit_track')->toContain('registration_status');
});

test('the employment track is still asked for any other goal', function () {
    app(RecordFactChange::class)->execute($this->actor, $this->case->person->fresh(), 'case_goal', 'understand_options', null, $this->case->fresh()->fact_version);

    expect(($this->questions)())->toContain('permit_track');
});
