<?php

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Assessment\CriterionResult;
use App\Bureaucracy\Assessment\EvaluateCriteria;
use Carbon\CarbonImmutable;

function assessmentVariant(string $id, array $conditions, string $coverage = 'partial'): array
{
    return ['id' => $id, 'task_key' => $id, 'kind' => 'preparation', 'coverage' => $coverage,
        'coverage_review' => $coverage === 'complete' ? ['criterion_keys' => array_keys($conditions[0]), 'source_review_reference' => 'synthetic_only'] : null,
        'jurisdiction' => 'synthetic-city', 'conditions' => $conditions, 'title' => 'Synthetic case',
        'description' => 'Fixture only, not legal guidance.', 'type' => 'task', 'phase' => 'arrival',
        'occurrence_fact' => null, 'step_id' => $id.'.complete', 'instructions' => [], 'documents' => [], 'actions' => [],
        'review' => ['review_status' => 'approved', 'review_due_at' => '2027-01-01'],
        'source_hash' => 'synthetic', 'deadline' => ['type' => 'none', 'fact_key' => null, 'days' => null], 'depends_on' => []];
}

function assessmentInput(array $variants, array $values, array $states = [], string $date = '2026-09-08', ?string $goal = null): AssessmentInput
{
    return new AssessmentInput(
        facts: ['revision' => 1, 'values' => $values, 'states' => $states, 'evidence' => []],
        relationships: [], processes: [],
        catalogue: ['release_hash' => 'synthetic', 'withdrawn' => [], 'definitions' => array_map(fn ($variant) => ['id' => $variant['id'], 'topic' => 'residence', 'variants' => [$variant]], $variants)],
        jurisdiction: 'synthetic-city', at: new CarbonImmutable($date.' 12:00:00', 'Europe/Berlin'), goal: $goal,
    );
}

test('missing and conflicting facts block only their own process', function () {
    $registration = assessmentVariant('address', [['moved_in_at' => ['present' => true]]]);
    $renewal = assessmentVariant('renewal', [['current_residence_title' => 'family_reunification', 'marital_household_continues' => true]]);
    $input = assessmentInput([$registration, $renewal], ['moved_in_at' => '2026-09-01', 'current_residence_title' => 'family_reunification'], ['marital_household_continues' => 'conflict']);
    $result = (new AssessPerson)->assess($input)->toArray();
    $byId = array_column($result['processes'], null, 'definition_id');
    expect($byId['address']['variants'][0]['assessment'])->toBe('supported_preparation')
        ->and($byId['renewal']['variants'][0]['assessment'])->toBe('needs_information')
        ->and($byId['renewal']['variants'][0]['criteria'][1]['status'])->toBe('conflict');
});

test('a selected renewal goal prioritises but does not suppress other supported processes', function () {
    $renewal = assessmentVariant('renewal', [['case_goal' => 'renew_current_title', 'current_residence_title' => 'family_reunification']]);
    $other = assessmentVariant('settlement', [['case_goal' => 'settlement_permit', 'current_residence_title' => 'family_reunification', 'german_level' => 'b1']]);
    $result = (new AssessPerson)->assess(assessmentInput([$other, $renewal], ['current_residence_title' => 'family_reunification', 'german_level' => 'b1'], goal: 'renew_current_title'))->toArray();
    expect(array_column($result['processes'], 'definition_id'))->toBe(['renewal', 'settlement'])
        ->and(array_column(array_merge(...array_column($result['processes'], 'variants')), 'assessment'))->toBe(['supported_preparation', 'supported_preparation']);
});

test('partial or duration-only coverage never claims all requirements met', function () {
    $variant = assessmentVariant('settlement', [['blue_card_qualifying_months' => ['gte' => 21]]]);
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['blue_card_qualifying_months' => 60]))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('supported_preparation')
        ->and($result['processes'][0]['coverage']['status'])->toBe('partial');
});

test('a met alternative wins without treating every failed alternative as a requirement', function () {
    $variant = assessmentVariant('alternative', [['german_level' => 'b1'], ['german_level' => 'b2']], 'complete');
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['german_level' => 'b2']))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('requirements_met');
});

test('the assessor uses only its explicit clock and rejects future qualifying dates', function () {
    $variant = assessmentVariant('history', [['family_residence_permit_held_since' => ['at_least_months_ago' => 36]]]);
    $assessor = new AssessPerson;
    $input = assessmentInput([$variant], ['family_residence_permit_held_since' => '2023-09-08']);
    CarbonImmutable::setTestNow(new CarbonImmutable('2035-01-01'));
    try {
        $one = $assessor->assess($input)->toArray();
        CarbonImmutable::setTestNow(new CarbonImmutable('2020-01-01'));
        expect($assessor->assess($input)->toArray())->toBe($one)
            ->and($one['processes'][0]['variants'][0]['assessment'])->toBe('supported_preparation');
        $future = $assessor->assess(assessmentInput([$variant], ['family_residence_permit_held_since' => '2027-01-01']))->toArray();
        expect($future['processes'][0]['variants'][0]['assessment'])->toBe('needs_information');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('expired guidance and another jurisdiction cannot produce actions or interviews', function () {
    $expired = assessmentVariant('expired', [['german_level' => 'b1']]);
    $expired['review']['review_due_at'] = '2026-09-07';
    $other = assessmentVariant('other_city', [['german_level' => 'b1']]);
    $other['jurisdiction'] = 'different-city';
    $result = (new AssessPerson)->assess(assessmentInput([$expired, $other], []))->toArray();
    expect($result['question_dependencies'])->toBe([])
        ->and(array_filter(array_column($result['processes'], 'variants')))->toBe([]);
});

test('an absent value is not proof of a negative fact', function () {
    $variant = assessmentVariant('absence', [['moved_in_at' => ['present' => false]]]);
    $result = (new AssessPerson)->assess(assessmentInput([$variant], []))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('needs_information');
});

test('an irrelevant process is not incorrectly labelled as missing legal coverage', function () {
    $variant = assessmentVariant('other_title', [['current_residence_title' => 'blue_card']]);
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['current_residence_title' => 'family_reunification']))->toArray();
    expect($result['processes'][0]['relevance'])->toBe('not_relevant')
        ->and($result['processes'][0]['coverage']['status'])->toBe('partial');
});

test('calendar-month windows are evaluated by the explicit local date at exact boundaries', function () {
    $variant = assessmentVariant('window', [['family_residence_permit_held_since' => ['months_ago_between' => [36, 59]]]]);
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['family_residence_permit_held_since' => '2023-09-08']))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('supported_preparation');
});

test('a missing expiry can ask a relevant question without hiding known preparation', function () {
    $variant = assessmentVariant('renewal', [['current_residence_title' => 'family_reunification']]);
    $variant['deadline'] = ['type' => 'fact_date', 'fact_key' => 'residence_title_expires_at', 'days' => null];
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['current_residence_title' => 'family_reunification']))->toArray();
    expect(array_column($result['question_dependencies'], 'fact_key'))->toContain('residence_title_expires_at')
        ->and($result['processes'][0]['variants'][0]['actionable'])->toBeTrue();
});

test('linked sponsor data is used only as supplied and conflicting reports remain unresolved', function () {
    $variant = assessmentVariant('family', [['sponsor_current_title' => 'settlement_permit_18c']]);
    $base = assessmentInput([$variant], []);
    $make = fn ($facts, $relationships) => new AssessmentInput($facts, $relationships, [], $base->catalogue, $base->jurisdiction, $base->at);
    $sponsor = ['status' => 'available', 'values' => ['sponsor_current_title' => 'settlement_permit_18c'], 'dependency_token' => 'synthetic'];
    $yes = (new AssessPerson)->assess($make($base->facts, [$sponsor]))->toArray();
    expect($yes['processes'][0]['variants'][0]['assessment'])->toBe('supported_preparation');
    $conflict = (new AssessPerson)->assess($make(['values' => ['sponsor_current_title' => 'blue_card'], 'states' => []], [$sponsor]))->toArray();
    expect($conflict['processes'][0]['variants'][0]['criteria'][0]['status'])->toBe('conflict');
    $revoked = (new AssessPerson)->assess($make($base->facts, [['status' => 'access_unavailable', 'values' => [], 'dependency_token' => 'revoked']]))->toArray();
    expect($revoked['processes'][0]['variants'][0]['assessment'])->toBe('needs_information');
});

test('complementary sponsor facts from different people never create a composite sponsor', function (bool $reverse) {
    $variant = assessmentVariant('family', [['sponsor' => 'non_eu', 'sponsor_current_title' => 'settlement_permit_18c']], 'complete');
    $base = assessmentInput([$variant], []);
    $relationships = [
        ['status' => 'available', 'values' => ['sponsor' => 'non_eu'], 'dependency_token' => 'person-a'],
        ['status' => 'available', 'values' => ['sponsor_current_title' => 'settlement_permit_18c'], 'dependency_token' => 'person-b'],
    ];
    $result = (new AssessPerson)->assess(new AssessmentInput($base->facts, $reverse ? array_reverse($relationships) : $relationships, [], $base->catalogue, $base->jurisdiction, $base->at))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('needs_information')
        ->and($result['processes'][0]['variants'][0]['actionable'])->toBeFalse();
})->with([false, true]);

test('unbound sponsor reports cannot fill missing fields for a linked person', function () {
    $variant = assessmentVariant('family', [['sponsor' => 'non_eu', 'sponsor_current_title' => 'settlement_permit_18c']], 'complete');
    $base = assessmentInput([$variant], ['sponsor' => 'non_eu']);
    $result = (new AssessPerson)->assess(new AssessmentInput($base->facts,
        [['status' => 'available', 'values' => ['sponsor_current_title' => 'settlement_permit_18c']]],
        [], $base->catalogue, $base->jurisdiction, $base->at))->toArray();
    expect($result['processes'][0]['variants'][0]['assessment'])->toBe('needs_information');
});

test('deadline dependencies resolve uncertain selectors before picking their dates', function (string $state, string $selector) {
    $variant = assessmentVariant('prepare', []);
    $variant['deadline'] = $selector === 'entry_mode'
        ? ['type' => 'permit_window', 'fact_key' => null, 'days' => null]
        : ['type' => 'fact_date', 'fact_key' => 'residence_title_expires_at', 'days' => null];
    $values = ['entry_mode' => 'd_visa', 'visa_expires_at' => '2026-12-01', 'current_residence_title' => 'settlement_permit_18c'];
    $result = (new AssessPerson)->assess(assessmentInput([$variant], $values, [$selector => $state]))->toArray();
    expect(array_column($result['question_dependencies'], 'fact_key'))->toBe([$selector]);
})->with(['conflict', 'needs_reconfirmation'])->with(['entry_mode', 'current_residence_title']);

test('explicit non-applicability answers both presence predicates without asking again', function (bool $present) {
    $evaluator = new EvaluateCriteria;
    $facts = ['values' => [], 'states' => ['moved_in_at' => 'not_applicable']];
    $at = new CarbonImmutable('2026-09-08');
    expect($evaluator->condition('moved_in_at', ['present' => $present], $facts, $at))->toBe($present ? CriterionResult::Unmet : CriterionResult::Met)
        ->and($evaluator->evaluate([['moved_in_at' => ['present' => $present]]], $facts, $at)['missing'])->toBe([]);
})->with([false, true]);

test('a satisfied alternative for another goal cannot swallow the chosen actions missing answers', function (string $state) {
    $variant = assessmentVariant('choice', [
        ['case_goal' => 'blue_card', 'arrival_planned' => false],
        ['case_goal' => 'renew_current_title', 'livelihood_secured' => 'yes'],
    ]);
    $variant['action_requires_intent'] = true;
    $input = assessmentInput([$variant], ['case_goal' => 'renew_current_title', 'arrival_planned' => false], ['livelihood_secured' => $state]);
    $result = (new AssessPerson)->assess($input)->toArray();
    $decision = $result['processes'][0]['variants'][0];
    expect($decision['assessment'])->toBe('supported_preparation')
        ->and($decision['actionable'])->toBeFalse()
        ->and(array_column($result['question_dependencies'], 'fact_key'))->toBe(['livelihood_secured']);
})->with(['unknown', 'conflict', 'needs_reconfirmation']);

test('a matching satisfied alternative does not interview for other unfinished alternatives', function () {
    $variant = assessmentVariant('choice', [
        ['case_goal' => 'renew_current_title', 'arrival_planned' => false],
        ['case_goal' => 'renew_current_title', 'livelihood_secured' => 'yes'],
    ]);
    $variant['action_requires_intent'] = true;
    $result = (new AssessPerson)->assess(assessmentInput([$variant], ['case_goal' => 'renew_current_title', 'arrival_planned' => false]))->toArray();
    expect($result['processes'][0]['variants'][0]['actionable'])->toBeTrue()
        ->and($result['question_dependencies'])->toBe([]);
});
