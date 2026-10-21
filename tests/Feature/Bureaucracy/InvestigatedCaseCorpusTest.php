<?php

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\People\EnsureAccountHolder;
use App\Bureaucracy\QA\ScenarioAssessmentPreview;
use App\Bureaucracy\ReadModel\PlanReadModel;
use App\Models\Task;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage(app(CatalogueCompiler::class)->compile(Task::whereNotNull('key')->get()->all()))->id, null);
});

dataset('investigated case corpus', collect(require __DIR__.'/../../Fixtures/bureaucracy/cases/investigated-cases.php')
    ->map(fn (array $fixture, string $name): array => [$name, $fixture])
    ->all());

test('each investigated scenario receives the same bounded decisions in preview and the canonical plan', function (string $name, array $fixture) {
    $preview = app(ScenarioAssessmentPreview::class)->forKey($fixture['persona'], 'de-nrw-cologne');
    expect($preview)->not->toBeNull("Missing persona for {$name}");
    $user = User::factory()->onboarded()->create();
    $case = app(EnsureAccountHolder::class)->dossier($user);
    foreach ($preview['facts']['values'] as $key => $value) {
        app(RecordFactChange::class)->execute($user, $case->person, $key, $value, null, $case->fresh()->fact_version);
    }
    $plan = app(PlanReadModel::class)->for($user, $case->person, 'de-nrw-cologne');
    $guidance = collect($plan['guidance']);
    $residence = $guidance->filter(fn ($row) => str_starts_with($row['id'], 'case.') && $row['kind'] !== 'context');
    expect($plan['coverage']['state'])->toBe('partial')
        ->and($residence->where('assessment', 'supported_preparation')->pluck('id')->sort()->values()->all())
        ->toBe(collect($fixture['matched'])->sort()->values()->all(), $name)
        ->and($residence->where('assessment', 'needs_information')->pluck('id')->sort()->values()->all())
        ->toBe(collect($fixture['unknown'])->sort()->values()->all(), $name)
        ->and($guidance->pluck('assessment'))->not->toContain('requirements_met');
    $previewVariants = collect($preview['assessment']['processes'])->flatMap(fn ($row) => $row['variants'])->keyBy('id');
    foreach ($guidance as $row) {
        expect($row['assessment'])->toBe($previewVariants[$row['id']]['assessment'])
            ->and($row['missing_facts'])->toBe($previewVariants[$row['id']]['missing_facts']);
    }
    foreach ($fixture['sections']['do_now'] ?? [] as $key) {
        expect(array_column($plan['actions'], 'source_rule_id'))->toContain($key);
    }
    foreach ([...($fixture['sections']['coming_up'] ?? []), ...($fixture['sections']['options'] ?? [])] as $key) {
        expect($guidance->firstWhere('id', $key)['kind'])->toBe('option')
            ->and(array_column($plan['actions'], 'source_rule_id'))->not->toContain($key);
    }
    foreach ($fixture['universal'] ?? [] as $key) {
        expect($guidance->firstWhere('id', $key)['kind'])->toBe('context');
    }
    foreach ($fixture['unknown'] as $key) {
        expect($guidance->firstWhere('id', $key)['missing_facts'])->toBe($fixture['missing']);
    }
    if (isset($fixture['information_needed'])) {
        $registry = app(FactRegistry::class);
        $questions = array_map(fn ($key) => [
            'question' => $registry->definition($key)->question,
            'why' => $registry->definition($key)->why,
        ], $fixture['missing']);
        expect($questions)->toBe($fixture['information_needed']);
        expect(array_column($preview['assessment']['question_dependencies'], 'fact_key'))->toContain(...$fixture['missing']);
    }
    foreach ($fixture['deadlines'] ?? [] as $key => $date) {
        $event = collect($plan['timeline'])->firstWhere('source_rule_id', $key);
        expect($event)->not->toBeNull()->and($event['date'])->toBe($date)
            ->and($event['kind'])->toBe('preparation_target');
    }
    foreach ($fixture['absent'] ?? [] as $key) {
        expect($guidance->pluck('id'))->not->toContain($key);
    }
    // A persona's housing label is not evidence of an unfinished registration.
    expect($preview['facts']['values'])->not->toHaveKey('registration_status')
        ->and(array_column($plan['actions'], 'source_rule_id'))->not->toContain('core.anmeldung', 'case.family.register_address')
        ->and($guidance->firstWhere('id', 'core.anmeldung')['missing_facts'])->toContain('registration_status');
    $copy = strtolower($guidance->pluck('description')->filter()->implode(' '));
    if (isset($fixture['required_phrase'])) {
        expect($copy)->toContain(strtolower($fixture['required_phrase']));
    }
    foreach ($fixture['forbidden_phrases'] ?? [] as $phrase) {
        expect($copy)->not->toContain(strtolower($phrase));
    }
})->with('investigated case corpus');
