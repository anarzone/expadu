<?php

use App\Bureaucracy\Evidence\EvidenceRequirements;
use Carbon\CarbonImmutable;

function evidenceVariant(): array
{
    return ['id' => 'synthetic.task', 'conditions' => [], 'source_hash' => 'test-only',
        'review' => ['review_status' => 'approved', 'review_due_at' => '2027-01-01'], 'kind' => 'preparation',
        'documents' => [['id' => 'synthetic.paper', 'label' => 'Synthetic paper', 'semantic_hash' => 'paper.v1', 'applies_if' => [['purpose' => 'family']]]],
        'instructions' => []];
}

test('a conditional paper is not described as required until its condition is known', function (array $facts, string $status) {
    $row = (new EvidenceRequirements)->for([evidenceVariant()], $facts, new CarbonImmutable('2026-09-08'))[0];
    expect($row['applicability'])->toBe($status)->and($row)->not->toHaveKey('applies_if');
})->with([
    [['values' => ['purpose' => 'family']], 'required'],
    [['values' => ['purpose' => 'employment']], 'not_required'],
    [['values' => []], 'unknown'],
    [['values' => ['purpose' => 'family'], 'states' => ['purpose' => 'conflict']], 'unknown'],
]);

test('a branch label alone cannot choose a document route and reviewed predicates can', function () {
    $variant = evidenceVariant();
    $variant['documents'][0]['applies_if'] = [];
    $variant['documents'][0]['branch'] = 'synthetic-route';
    $variant['instructions'] = [['id' => 'route', 'branch' => 'synthetic-route']];
    $requirements = new EvidenceRequirements;
    $at = new CarbonImmutable('2026-09-08');
    expect($requirements->for([$variant], ['values' => []], $at)[0]['applicability'])->toBe('unknown');
    $variant['instructions'][0]['applies_if'] = [['purpose' => 'family']];
    expect($requirements->for([$variant], ['values' => ['purpose' => 'family']], $at)[0]['applicability'])->toBe('required');
    expect($requirements->for([$variant], ['values' => ['purpose' => 'employment']], $at)[0]['applicability'])->toBe('not_required');
});

test('a document checklist is scoped to current reviewed guidance and does not count a hypothetical route as required', function () {
    $variant = evidenceVariant();
    $variant['kind'] = 'option';
    $at = new CarbonImmutable('2026-09-08');
    expect((new EvidenceRequirements)->for([$variant], ['values' => ['purpose' => 'family']], $at)[0]['applicability'])->toBe('conditional');
    $variant['review']['review_due_at'] = '2026-09-07';
    expect((new EvidenceRequirements)->for([$variant], ['values' => ['purpose' => 'family']], $at))->toBe([]);
});
