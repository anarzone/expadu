<?php

use App\Bureaucracy\Ai\CaseFactExtractionRequest;
use App\Bureaucracy\Ai\CaseFactExtractionResult;
use App\Bureaucracy\Ai\CaseFactToolSchema;
use App\Bureaucracy\Ai\Contracts\ExtractsCaseFact;
use App\Bureaucracy\Ai\UnavailableCaseFactExtractor;
use App\Bureaucracy\Facts\FactRegistry;

test('the extraction contract accepts only one server authorized fact request', function () {
    $method = new ReflectionMethod(ExtractsCaseFact::class, 'extract');

    expect($method->getParameters())->toHaveCount(1)
        ->and($method->getParameters()[0]->getType()?->getName())->toBe(CaseFactExtractionRequest::class)
        ->and($method->getReturnType()?->getName())->toBe(CaseFactExtractionResult::class);

    $request = new CaseFactExtractionRequest(
        factKey: 'weekly_work_hours',
        question: 'How many hours per week do you currently work?',
        why: 'This route depends on documented weekly work.',
        message: 'I work 20 hours.',
    );

    expect(get_object_vars($request))->toBe([
        'factKey' => 'weekly_work_hours',
        'question' => 'How many hours per week do you currently work?',
        'why' => 'This route depends on documented weekly work.',
        'message' => 'I work 20 hours.',
    ]);
});

test('results expose exactly the five allowed outcomes and only candidates carry values', function () {
    $candidateFalse = CaseFactExtractionResult::candidate(false);
    $candidateZero = CaseFactExtractionResult::candidate(0);

    expect($candidateFalse->outcome)->toBe('candidate')
        ->and($candidateFalse->value)->toBeFalse()
        ->and($candidateFalse->hasValue)->toBeTrue()
        ->and($candidateZero->value)->toBe(0)
        ->and($candidateZero->hasValue)->toBeTrue();

    foreach (['unknown', 'offTopic', 'unavailable', 'invalid'] as $constructor) {
        $result = CaseFactExtractionResult::{$constructor}();

        expect($result->outcome)->toBeIn(['unknown', 'off_topic', 'unavailable', 'invalid'])
            ->and($result->value)->toBeNull()
            ->and($result->hasValue)->toBeFalse();
    }
});

test('result invariants cannot be bypassed through a public constructor', function () {
    expect((new ReflectionClass(CaseFactExtractionResult::class))->getConstructor()?->isPrivate())->toBeTrue();
});

test('a candidate must carry a non-null typed value', function () {
    CaseFactExtractionResult::candidate(null);
})->throws(InvalidArgumentException::class);

test('the forced tool schema exposes only outcome and the authorized canonical value type', function (string $factKey, array $expectedValueSchema) {
    $definition = (new FactRegistry)->definition($factKey);
    $tool = (new CaseFactToolSchema)->for($definition);
    $function = $tool['function'];
    $parameters = $function['parameters'];
    $variants = $parameters['properties']['result']['anyOf'];
    $candidate = $variants[0];

    expect($tool['type'])->toBe('function')
        ->and($function['name'])->toBe('extract_authorized_fact')
        ->and($function['strict'])->toBeTrue()
        ->and(array_keys($parameters['properties']))->toBe(['result'])
        ->and($parameters['required'])->toBe(['result'])
        ->and($parameters['additionalProperties'])->toBeFalse()
        ->and($candidate['properties']['value'])->toBe($expectedValueSchema)
        ->and($candidate['required'])->toBe(['outcome', 'value'])
        ->and($candidate['additionalProperties'])->toBeFalse()
        ->and($variants[1]['required'])->toBe(['outcome'])
        ->and($variants[1]['additionalProperties'])->toBeFalse()
        ->and($variants[2]['required'])->toBe(['outcome'])
        ->and($variants[2]['additionalProperties'])->toBeFalse();
})->with([
    'enum' => ['german_level', ['type' => 'string', 'enum' => ['none', 'a1', 'a2', 'b1', 'b2', 'c1', 'c2']]],
    'date' => ['residence_title_expires_at', ['type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$']],
    'integer' => ['weekly_work_hours', ['type' => 'integer', 'minimum' => 0]],
    'boolean' => ['marital_household_continues', ['type' => 'boolean']],
]);

test('the unavailable implementation always returns an unavailable result', function () {
    $result = (new UnavailableCaseFactExtractor)->extract(new CaseFactExtractionRequest(
        factKey: 'german_level',
        question: 'What is your documented German level?',
        why: 'A settlement route depends on proof.',
        message: 'B1',
    ));

    expect($result->outcome)->toBe('unavailable')
        ->and($result->hasValue)->toBeFalse();
});
