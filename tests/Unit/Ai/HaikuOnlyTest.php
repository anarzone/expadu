<?php

/*
| Owner, 21 Oct 2026: every AI feature runs on Claude Haiku, never Sonnet or Opus.
*/

const AI_MODEL_KEYS = [
    'anthropic' => 'ANTHROPIC_MODEL',
    'transit_briefs' => 'TRANSIT_BRIEFS_MODEL',
    'place_enrichment' => 'PLACE_ENRICHMENT_MODEL',
    'taste_profile' => 'TASTE_PROFILE_MODEL',
    'composer_llm' => 'COMPOSER_LLM_MODEL',
    'llm' => 'LLM_MODEL',
    'bureaucracy_llm' => 'BUREAUCRACY_LLM_MODEL',
    'paperwork_analyser' => 'PAPERWORK_ANALYSER_MODEL',
];

function servicesWithModelEnv(?string $model): array
{
    foreach (AI_MODEL_KEYS as $env) {
        $model === null ? putenv($env) : putenv("{$env}={$model}");
        if ($model === null) {
            unset($_ENV[$env], $_SERVER[$env]);
        } else {
            $_ENV[$env] = $_SERVER[$env] = $model;
        }
    }

    try {
        return require dirname(__DIR__, 3).'/config/services.php';
    } finally {
        servicesWithModelEnvReset();
    }
}

function servicesWithModelEnvReset(): void
{
    foreach (AI_MODEL_KEYS as $env) {
        putenv($env);
        unset($_ENV[$env], $_SERVER[$env]);
    }
}

it('runs every AI feature on Haiku by default', function () {
    $services = servicesWithModelEnv(null);

    foreach (array_keys(AI_MODEL_KEYS) as $feature) {
        expect($services[$feature]['model'])->toBe('claude-haiku-5-5');
    }
});

it('never lets an env setting switch a feature to Sonnet or Opus', function (string $model) {
    $services = servicesWithModelEnv($model);

    foreach (array_keys(AI_MODEL_KEYS) as $feature) {
        expect($services[$feature]['model'])->toBe('claude-haiku-5-5');
    }
})->with(['claude-sonnet-5-5', 'claude-opus-5-5', 'gpt-5']);

it('still allows another Haiku version', function () {
    expect(servicesWithModelEnv('claude-haiku-6-0')['llm']['model'])->toBe('claude-haiku-6-0');
});
