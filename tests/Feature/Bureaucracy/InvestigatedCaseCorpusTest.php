<?php

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Cases\CaseMatcher;
use App\Bureaucracy\Cases\CasePlanComposer;
use App\Bureaucracy\QA\ScenarioFactSynchronizer;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-08-03 10:00:00');
});

test('the six investigated cases produce bounded verified plans', function () {
    $this->artisan('bureaucracy:import-tasks', ['--prune' => true])->assertSuccessful();

    $scenarios = collect(BureaucracyPersonas::caseScenarios())->keyBy('key');
    $fixtures = require base_path('tests/Fixtures/bureaucracy/cases/investigated-cases.php');

    foreach ($fixtures as $name => $fixture) {
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
    }
});
