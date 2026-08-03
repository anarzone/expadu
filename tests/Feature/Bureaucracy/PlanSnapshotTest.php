<?php

use App\Bureaucracy\Cases\PlanSnapshotStore;
use App\Bureaucracy\Facts\CaseFactStore;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPlanSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

beforeEach(function () {
    $this->travelTo('2026-08-03 10:00:00');
});

/**
 * @param  list<array<string, mixed>>|null  $appliesIf
 * @param  array<string, mixed>  $overrides
 */
function task5SnapshotRule(string $key, ?array $appliesIf = null, array $overrides = []): Task
{
    return Task::factory()->create(array_replace([
        'key' => $key,
        'title' => "Rule {$key}",
        'description' => "Verified guidance for {$key}.",
        'type' => 'task',
        'applies_if' => $appliesIf,
        'phase' => 'arrival',
        'depends_on' => [],
        'deadline_type' => 'none',
        'deadline_days' => null,
        'urgency' => 'high',
        'is_published' => true,
        'jurisdiction' => 'de-nrw-cologne',
        'legal_sources' => [
            [
                'kind' => 'primary',
                'label' => 'AufenthG',
                'url' => 'https://www.gesetze-im-internet.de/aufenthg_2004/__18g.html',
            ],
            [
                'kind' => 'implementation',
                'label' => 'Stadt Köln',
                'url' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
            ],
        ],
        'review_status' => 'approved',
        'source_verification' => 'dual_source',
        'reviewed_by' => 'expadu_content_owner',
        'content_version' => '2026-08-03.1',
        'verified_at' => '2026-08-03',
        'review_due_at' => '2027-08-03',
        'conflicts_with' => [],
        'coverage_scope' => 'case',
    ], $overrides));
}

/** @param array<string, mixed> $facts */
function task5SnapshotCase(array $facts = []): BureaucracyCase
{
    $user = User::factory()->onboarded()->create([
        'situation' => 'other',
        'is_eu' => false,
        'german_level' => null,
        'profile_attributes' => [],
    ]);
    $case = BureaucracyCase::factory()->for($user)->create();

    foreach ($facts as $key => $value) {
        BureaucracyCaseFact::factory()->create([
            'case_id' => $case->id,
            'key' => $key,
            'value' => $value,
            'state' => 'confirmed',
            'confirmed_at' => now(),
            'reconfirm_at' => now()->addYear(),
            'superseded_at' => null,
        ]);
    }

    return $case;
}

test('identical reads reuse one active snapshot with stable approved sections', function () {
    task5SnapshotRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    task5SnapshotRule('universal.keep-documents', null, [
        'coverage_scope' => 'universal',
        'content_version' => '2026-08-03.2',
    ]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    $store = app(PlanSnapshotStore::class);

    $first = $store->store($case);
    $second = $store->store($case);

    $sectionItems = collect($first->sections)->flatten(1)->filter(fn (mixed $item): bool => is_array($item) && isset($item['key']));

    expect($second->id)->toBe($first->id)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(1)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->whereNull('superseded_at')->count())->toBe(1)
        ->and(array_keys($first->sections))->toBe([
            'current_status',
            'do_now',
            'next',
            'coming_up',
            'options',
            'waiting',
            'information_needed',
            'not_covered',
        ])
        ->and($sectionItems)->each(function ($item): void {
            $item->toHaveKeys(['key', 'content_version']);
        });
});

test('a confirmed fact change creates exactly one replacement snapshot', function () {
    task5SnapshotRule('case.blue-card', [['case_goal' => 'blue_card']]);
    $case = task5SnapshotCase();
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    $candidate = app(CaseFactStore::class)->recordCandidate(
        $case,
        'case_goal',
        'blue_card',
        'test_confirmation',
    );
    app(CaseFactStore::class)->confirmCandidate($candidate);

    $second = $store->store($case);
    $reloaded = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and($reloaded->id)->toBe($second->id)
        ->and($first->fresh()->superseded_at)->not->toBeNull()
        ->and($second->fact_version)->toBe($case->fresh()->fact_version)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->whereNull('superseded_at')->count())->toBe(1);
});

test('an approved content version change creates one replacement snapshot', function () {
    $task = task5SnapshotRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    $task->update(['content_version' => '2026-08-03.2']);

    $second = $store->store($case);
    $reloaded = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and($reloaded->id)->toBe($second->id)
        ->and($second->rule_versions['case.blue-card'])->toBe('2026-08-03.2')
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2);
});

test('dependency completion changes the signature and moves the unlocked rule out of waiting', function () {
    $dependency = task5SnapshotRule('case.registration', [['current_residence_title' => 'blue_card']], [
        'review_status' => 'legacy',
    ]);
    task5SnapshotRule('case.application', [['current_residence_title' => 'blue_card']], [
        'depends_on' => ['case.registration'],
    ]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    $userTask = UserTask::factory()->create([
        'user_id' => $case->user_id,
        'task_id' => $dependency->id,
        'status' => TaskStatus::NotStarted,
        'completed_at' => null,
    ]);
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    expect(collect($first->sections['waiting'])->pluck('key')->all())->toContain('case.application');

    $userTask->update([
        'status' => TaskStatus::Done,
        'completed_at' => now(),
    ]);

    $second = $store->store($case);
    $reloaded = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and($reloaded->id)->toBe($second->id)
        ->and(collect($second->sections['waiting'])->pluck('key')->all())->not->toContain('case.application')
        ->and(collect($second->sections['do_now'])->pluck('key')->all())->toContain('case.application')
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2);
});

test('explicit reassessment and a reached reassessment boundary each create one replacement', function () {
    task5SnapshotRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    $explicit = $store->store($case, explicitReassessment: true);
    $explicitReload = $store->store($case);

    expect($explicit->id)->not->toBe($first->id)
        ->and($explicitReload->id)->toBe($explicit->id)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2);

    $explicit->update(['reassessment_at' => now()->subSecond()]);

    $reached = $store->store($case);
    $reachedReload = $store->store($case);

    expect($reached->id)->not->toBe($explicit->id)
        ->and($reachedReload->id)->toBe($reached->id)
        ->and($explicit->fresh()->superseded_at)->not->toBeNull()
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(3)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->whereNull('superseded_at')->count())->toBe(1);
});

test('crossing the date boundary creates one new daily snapshot and then stabilizes', function () {
    task5SnapshotRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    $this->travelTo('2026-08-04 00:01:00');

    $second = $store->store($case);
    $reloaded = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and($reloaded->id)->toBe($second->id)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2);
});

test('a fact freshness boundary invalidates the snapshot on the same day', function () {
    task5SnapshotRule('case.blue-card', [['current_residence_title' => 'blue_card']]);
    $case = task5SnapshotCase(['current_residence_title' => 'blue_card']);
    BureaucracyCaseFact::query()
        ->where('case_id', $case->id)
        ->where('key', 'current_residence_title')
        ->update(['reconfirm_at' => now()->addHour()]);
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    expect($first->coverage_state)->toBe('matched')
        ->and($first->reassessment_at?->equalTo(now()->addHour()))->toBeTrue();

    $this->travelTo('2026-08-03 11:01:00');

    $second = $store->store($case);
    $reloaded = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and($second->coverage_state)->toBe('needs_information')
        ->and($second->unresolved_facts)->toBe(['current_residence_title'])
        ->and($reloaded->id)->toBe($second->id)
        ->and(BureaucracyPlanSnapshot::where('case_id', $case->id)->count())->toBe(2);
});
