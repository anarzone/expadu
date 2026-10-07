<?php

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Bureaucracy\Timeline\BuildTimeline;
use App\Models\Task;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo('2026-09-08 10:00:00');
    $this->task = Task::factory()->approvedFixture()->create([
        'key' => 'fixture.policy', 'title' => 'Synthetic preparation', 'description' => 'Test content only.',
        'applies_if' => [['arrival_planned' => false, 'livelihood_secured' => 'yes']],
        'deadline_type' => 'days_since_move_in', 'deadline_days' => 14,
        'depends_on' => [], 'links' => [], 'documents_required' => [], 'how_to_steps' => [],
    ])->fresh();
    $this->policy = [
        'kind' => 'legal_due', 'version' => 'synthetic.policy.1',
        'content_version' => $this->task->content_version, 'reviewed_by' => $this->task->reviewed_by,
        'verified_at' => $this->task->verified_at->toDateString(),
        'source_url' => $this->task->legal_sources[0]['url'],
    ];
    $this->mapping = [$this->task->key => [
        'process_id' => 'fixture.process', 'topic' => 'address', 'kind' => 'preparation', 'coverage' => 'partial',
        'relevance_keys' => ['arrival_planned'], 'temporal_policy' => $this->policy,
    ]];
});

test('compiled relevance stops an irrelevant interview without dropping eligibility conditions', function () {
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $assess = fn (array $values) => (new AssessPerson)->assess(new AssessmentInput(
        ['values' => $values, 'states' => []], [], [], $artifact, 'de-nrw-cologne', CarbonImmutable::now(),
    ))->toArray();
    $planning = $assess(['arrival_planned' => true]);
    expect($planning['processes'][0]['relevance'])->toBe('not_relevant')
        ->and($planning['question_dependencies'])->toBe([]);
    $arrived = $assess(['arrival_planned' => false]);
    expect($arrived['processes'][0]['variants'][0]['assessment'])->toBe('needs_information')
        ->and(array_column($arrived['question_dependencies'], 'fact_key'))->toBe(['livelihood_secured']);
});

test('a version bound temporal policy survives compilation staging and timeline projection', function (string $kind) {
    $this->mapping[$this->task->key]['temporal_policy']['kind'] = $kind;
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $store = app(CatalogueReleaseStore::class);
    $release = $store->stage($artifact);
    $store->activate($release->id, null);
    $variant = $store->current()['definitions'][0]['variants'][0];
    // PostgreSQL JSONB reorders object keys; compare exact typed values, not storage key order.
    $actual = $variant['temporal_policy'] ?? [];
    $expected = $this->mapping[$this->task->key]['temporal_policy'];
    ksort($actual);
    ksort($expected);
    expect($actual)->toBe($expected);
    $rows = (new BuildTimeline)->for([[...$variant, 'actionable' => true]], ['values' => ['moved_in_at' => '2026-09-01']], [], CarbonImmutable::now(), 'Europe/Berlin');
    expect($rows[0]['kind'])->toBe($kind)->and($rows[0]['policy_version'])->toBe('synthetic.policy.1')
        ->and($rows[0]['date'])->toBe('2026-09-15');
})->with(['legal_due', 'preparation_target', 'authority_follow_up']);

test('malformed relevance selectors are rejected rather than silently ignored', function (mixed $selectors) {
    $this->mapping[$this->task->key]['relevance_keys'] = $selectors;
    expect(fn () => app(CatalogueCompiler::class)->compile([$this->task], $this->mapping))->toThrow(DomainException::class);
})->with([
    'not a list' => ['arrival_planned'],
    'non-string element' => [[false]],
    'invented fact' => [['invented_status']],
    'not in conditions' => [['current_residence_title']],
    'duplicate key' => [['arrival_planned', 'arrival_planned']],
    'map not list' => [['arrival_planned' => true]],
]);

test('unreviewed or malformed temporal metadata cannot enter a compiled release', function (array $change) {
    $this->mapping[$this->task->key]['temporal_policy'] = [...$this->policy, ...$change];
    expect(fn () => app(CatalogueCompiler::class)->compile([$this->task], $this->mapping))->toThrow(DomainException::class);
})->with([
    'unknown kind' => [['kind' => 'guaranteed_issuance']],
    'missing version' => [['version' => null]],
    'invalid version' => [['version' => "version\n"]],
    'wrong content version' => [['content_version' => 'older']],
    'unattributed reviewer' => [['reviewed_by' => 'someone_else']],
    'unverified source' => [['source_url' => 'https://example.com/law']],
    'implementation alone is not a legal deadline' => [['source_url' => 'https://www.stadt-koeln.de/service/produkte/00415/index.html']],
    'different verification date' => [['verified_at' => '2026-09-07']],
    'unrecognised setting' => [['extend_for_appointment' => true]],
]);

test('a policy without a defined clock fails rather than being discarded', function () {
    $this->task->deadline_type = 'none';
    expect(fn () => app(CatalogueCompiler::class)->compile([$this->task], $this->mapping))->toThrow(DomainException::class);
});

test('omitting decision policy preserves the conservative legacy interpretation', function () {
    unset($this->mapping[$this->task->key]['temporal_policy'], $this->mapping[$this->task->key]['relevance_keys']);
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $variant = $artifact['definitions'][0]['variants'][0];
    $rows = (new BuildTimeline)->for([[...$variant, 'actionable' => true]], ['values' => ['moved_in_at' => '2026-09-01']], [], CarbonImmutable::now(), 'Europe/Berlin');
    expect($rows[0]['kind'])->toBe('preparation_target');
});

test('intent settings must describe an explicit application choice in every alternative', function (array $changes) {
    $this->task->applies_if = [['arrival_planned' => false, 'case_goal' => 'blue_card']];
    $this->mapping[$this->task->key]['action_requires_intent'] = true;
    if (array_key_exists('conditions', $changes)) {
        $this->task->applies_if = $changes['conditions'];
    }
    $this->mapping[$this->task->key] = [...$this->mapping[$this->task->key], ...($changes['mapping'] ?? [])];
    expect(fn () => app(CatalogueCompiler::class)->compile([$this->task], $this->mapping))->toThrow(DomainException::class);
})->with([
    'string flag' => [['mapping' => ['action_requires_intent' => 'true']]],
    'null flag' => [['mapping' => ['action_requires_intent' => null]]],
    'context not an application' => [['mapping' => ['kind' => 'context']]],
    'no preference' => [['conditions' => [['arrival_planned' => false]]]],
    'unconditional variant' => [['conditions' => []]],
    'alternative without preference' => [['conditions' => [['arrival_planned' => false, 'case_goal' => 'blue_card'], ['arrival_planned' => true]]]],
]);

test('intent gating survives activation and cannot be forged by the priority goal', function () {
    $this->task->applies_if = [['arrival_planned' => false, 'case_goal' => 'blue_card']];
    $this->task->save();
    $this->task->refresh();
    $this->mapping[$this->task->key]['action_requires_intent'] = true;
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage($artifact)->id, null);
    $assess = fn (array $values) => (new AssessPerson)->assess(new AssessmentInput(['values' => $values], [], [],
        $store->current(), 'de-nrw-cologne', CarbonImmutable::now(), 'blue_card'))->toArray();
    $unrequested = $assess(['arrival_planned' => false, 'case_goal' => 'renew_current_title']);
    expect($unrequested['processes'][0]['variants'][0]['actionable'])->toBeFalse()
        ->and(array_column($unrequested['question_dependencies'], 'fact_key'))->not->toContain('case_goal');
    $requested = $assess(['arrival_planned' => false, 'case_goal' => 'blue_card']);
    expect($requested['processes'][0]['variants'][0]['actionable'])->toBeTrue();
});

test('uncertain relevance facts do not suppress the questions needed to resolve them', function (string $state) {
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $values = $state === 'unknown' ? [] : ['arrival_planned' => true];
    $result = (new AssessPerson)->assess(new AssessmentInput(
        ['values' => $values, 'states' => ['arrival_planned' => $state]], [], [], $artifact,
        'de-nrw-cologne', CarbonImmutable::now(),
    ))->toArray();
    expect($result['processes'][0]['relevance'])->toBe('unknown')
        ->and(array_column($result['question_dependencies'], 'fact_key'))->toContain('arrival_planned');
})->with(['unknown', 'conflict', 'needs_reconfirmation']);

test('an alternative without the relevance selector still retains its missing requirement', function () {
    $this->task->applies_if = [['arrival_planned' => false], ['livelihood_secured' => 'yes']];
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $result = (new AssessPerson)->assess(new AssessmentInput(
        ['values' => ['arrival_planned' => true], 'states' => []], [], [], $artifact,
        'de-nrw-cologne', CarbonImmutable::now(),
    ))->toArray();
    expect($result['processes'][0]['relevance'])->toBe('relevant')
        ->and(array_column($result['question_dependencies'], 'fact_key'))->toBe(['livelihood_secured']);
});

test('withdrawing the source review withdraws an already compiled legal deadline', function (string $change) {
    $artifact = app(CatalogueCompiler::class)->compile([$this->task], $this->mapping);
    $store = app(CatalogueReleaseStore::class);
    $store->activate($store->stage($artifact)->id, null);
    expect($store->current()['definitions'][0]['variants'])->toHaveCount(1);
    match ($change) {
        'expiry' => $this->task->update(['review_due_at' => '2026-09-07']),
        'source removed' => $this->task->update(['legal_sources' => [$this->task->legal_sources[1]]]),
        'source changed' => $this->task->update(['legal_sources' => [
            [...$this->task->legal_sources[0], 'url' => 'https://www.gesetze-im-internet.de/bmg/__19.html'],
            $this->task->legal_sources[1],
        ]]),
    };
    $current = $store->current();
    $assessment = (new AssessPerson)->assess(new AssessmentInput(
        ['values' => ['arrival_planned' => false, 'livelihood_secured' => 'yes', 'moved_in_at' => '2026-09-01'], 'states' => []],
        [], [], $current, 'de-nrw-cologne', CarbonImmutable::now(),
    ))->toArray();
    $variants = array_merge(...array_column($assessment['processes'], 'variants'));
    expect($current['withdrawn'])->toContain($this->task->key)
        ->and((new BuildTimeline)->for($variants, ['values' => ['moved_in_at' => '2026-09-01']], [], CarbonImmutable::now(), 'Europe/Berlin'))->toBe([]);
})->with(['expiry', 'source removed', 'source changed']);
