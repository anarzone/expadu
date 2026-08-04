<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\QA\ScenarioFactSynchronizer;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-08-03 10:00:00');
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();
});

dataset('investigated case corpus', collect(require __DIR__.'/../../Fixtures/bureaucracy/cases/investigated-cases.php')
    ->map(fn (array $fixture, string $name): array => [$name, $fixture])
    ->all());

test('an investigated case produces its independently reviewed bounded plan', function (string $name, array $fixture) {
    $scenarios = collect(BureaucracyPersonas::caseScenarios())->keyBy('key');
    $persona = $scenarios->get($fixture['persona']);
    expect($persona)->not->toBeNull("Missing persona for {$name}");

    $user = User::factory()->onboarded()->create(BureaucracyPersonas::persistableProfile($persona));
    $case = app(ScenarioFactSynchronizer::class)->sync($user, $persona);
    $result = app(CaseMatcher::class)->match($case);
    $sections = app(CasePlanComposer::class)->compose($case, $result);

    expect($result->coverageState->value)->toBe($fixture['coverage'], $name)
        ->and($result->matchedRuleKeys)->toBe($fixture['matched'], $name)
        ->and($result->unknownRuleKeys)->toBe($fixture['unknown'], $name);

    if (isset($fixture['missing'])) {
        expect($result->missingFactKeys)->toBe($fixture['missing'], $name);
    }

    if (isset($fixture['universal'])) {
        expect($result->universalRuleKeys)->toBe($fixture['universal'], $name);
    }

    foreach ($fixture['sections'] as $section => $expectedKeys) {
        $actualKeys = collect($sections[$section])->pluck('key')->filter()->sort()->values()->all();
        $sortedExpected = collect($expectedKeys)->sort()->values()->all();

        expect($actualKeys)->toBe($sortedExpected, "{$name}: {$section}");
    }

    if (isset($fixture['information_needed'])) {
        $questions = collect($sections['information_needed'])
            ->flatMap(fn (array $item): array => $item['questions'] ?? [])
            ->unique(fn (array $question): string => $question['question'].'|'.$question['why'])
            ->values()
            ->all();

        expect($questions)->toBe($fixture['information_needed'], "{$name}: information needed");
    }

    $items = collect($sections)->flatten(1)->filter(fn (mixed $item): bool => is_array($item));

    foreach ($fixture['deadlines'] ?? [] as $key => $deadline) {
        expect($items->firstWhere('key', $key)['deadline'] ?? null)->toBe($deadline, "{$name}: {$key}");
    }

    foreach ($fixture['absent'] ?? [] as $key) {
        expect($items->pluck('key'))->not->toContain($key);
    }

    $copy = $items->pluck('description')->filter()->implode(' ');

    if (isset($fixture['required_phrase'])) {
        expect(strtolower($copy))->toContain(strtolower($fixture['required_phrase']));
    }

    foreach ($fixture['forbidden_phrases'] ?? [] as $phrase) {
        expect(strtolower($copy))->not->toContain(strtolower($phrase));
    }
})->with('investigated case corpus');
