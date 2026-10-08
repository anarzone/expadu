<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\Questions\DeferQuestion;
use App\Bureaucracy\Questions\OfferNextQuestion;
use App\Bureaucracy\Questions\QuestionSessions;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\BureaucracyCaseQuestion;
use App\Models\BureaucracyQuestionSession;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->freezeTime();
    $this->actor = User::factory()->create();
    $this->case = app(EnsureAccountHolder::class)->dossier($this->actor);
    $this->tasks = [];
    $mapping = [];
    foreach (['address', 'tax'] as $topic) {
        $key = 'fixture.coverage.'.$topic;
        $this->tasks[] = Task::factory()->approvedFixture()->create(['key' => $key, 'title' => 'Synthetic '.$topic.' unit', 'type' => 'task',
            'deadline_type' => 'none', 'applies_if' => [], 'depends_on' => [], 'documents_required' => [], 'how_to_steps' => [],
            'links' => ['https://www.stadt-koeln.de/service/produkte/00415/index.html']])->fresh();
        $mapping[$key] = ['process_id' => $key, 'position' => 1, 'topic' => $topic, 'kind' => 'preparation', 'coverage' => 'partial'];
    }
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile($this->tasks, $mapping))->id, null);
    $this->read = fn () => app(PlanReadModel::class)->for($this->actor, $this->case->person, 'de-nrw-cologne');
});

test('the question entry state, deferred fact keys and candidate count come from the interview without writing on read', function () {
    $fresh = ($this->read)()['questions'];
    expect($fresh['entry_state'])->toBe('none')->and($fresh['deferred'])->toBe([])
        ->and($fresh['candidates_count'])->toBeGreaterThan(0)->and($fresh['candidates_count'])->toBe($fresh['remaining_information_count'])
        ->and(BureaucracyQuestionSession::query()->count())->toBe(0);
    $session = app(QuestionSessions::class)->start($this->actor, $this->case->person, 'de-nrw-cologne', (string) Str::uuid());
    $offer = app(OfferNextQuestion::class)->execute($this->actor, $session, (string) Str::uuid())['question'];
    app(DeferQuestion::class)->execute($this->actor, $session, $offer['id'], $offer['token']);
    $deferred = ($this->read)()['questions'];
    expect($deferred['entry_state'])->toBe('in_progress')->and($deferred['deferred'])->toBe([$offer['fact_key']])
        ->and($deferred['candidates_count'])->toBe($fresh['candidates_count'])
        ->and($deferred['remaining_information_count'])->toBe($fresh['candidates_count'] - 1);
    $session->update(['status' => 'paused']);
    $offers = BureaucracyCaseQuestion::query()->count();
    expect(($this->read)()['questions'])->toMatchArray(['status' => 'paused', 'entry_state' => 'paused', 'deferred' => [$offer['fact_key']]])
        ->and(BureaucracyCaseQuestion::query()->count())->toBe($offers);
});

test('coverage lists each reviewed unit with its version, verification and sources, and keeps withdrawn units explicit', function () {
    $units = collect(($this->read)()['coverage']['units'])->keyBy('unit_id');
    expect($units->keys()->all())->toBe(['fixture.coverage.address', 'fixture.coverage.tax'])
        ->and($units['fixture.coverage.address'])->toMatchArray(['definition_id' => 'fixture.coverage.address', 'title' => 'Synthetic address unit',
            'content_version' => 'synthetic-fixture.1', 'verified_at' => today()->toDateString(), 'state' => 'partial'])
        ->and($units['fixture.coverage.address']['source_urls']['official'])->toBe(['https://www.stadt-koeln.de/service/produkte/00415/index.html'])
        ->and($units['fixture.coverage.address']['source_urls']['legal'])->toContain('https://www.gesetze-im-internet.de/bmg/__17.html');
    $this->tasks[1]->update(['is_published' => false]);
    $withdrawn = collect(($this->read)()['coverage']['units'])->firstWhere('unit_id', 'fixture.coverage.tax');
    expect($withdrawn)->toMatchArray(['state' => 'withdrawn', 'title' => null, 'definition_id' => 'fixture.coverage.tax'])
        ->and($withdrawn['source_urls']['official'])->toBe([]);
});

test('a unit past its review date is never reported as partial coverage', function () {
    $this->travel(2)->years();
    $units = ($this->read)()['coverage']['units'];
    expect(array_column($units, 'state'))->toBe(['withdrawn', 'withdrawn'])
        ->and(array_column($units, 'content_version'))->toBe(['synthetic-fixture.1', 'synthetic-fixture.1']);
});
