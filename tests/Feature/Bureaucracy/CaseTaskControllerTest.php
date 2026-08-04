<?php

use App\Bureaucracy\Cases\PlanSnapshotStore;
use App\Enums\TaskStatus;
use App\Models\BureaucracyCase;
use App\Models\BureaucracyCaseFact;
use App\Models\Task;
use App\Models\User;
use App\Models\UserTask;

/**
 * @param  list<array<string, mixed>>|null  $appliesIf
 * @param  array<string, mixed>  $overrides
 */
function caseTaskApprovedRule(string $key, ?array $appliesIf, array $overrides = []): Task
{
    return Task::factory()->create(array_replace([
        'key' => $key,
        'title' => "Verified task {$key}",
        'description' => 'Verified guidance.',
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
                'label' => 'Residence Act',
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

/**
 * @return array{0: User, 1: BureaucracyCase}
 */
function caseTaskUser(array $facts = ['case_goal' => 'blue_card']): array
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

    return [$user, $case];
}

test('case task progression requires authentication', function () {
    $task = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);

    $this->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
        'status' => 'done',
    ])->assertRedirect(route('login'));
});

test('a task outside the users active verified snapshot cannot be changed', function () {
    $visible = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);
    $unrelated = caseTaskApprovedRule('case.unrelated', [['case_goal' => 'settlement_permit']]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $unrelated->key]), [
            'status' => 'done',
        ])
        ->assertForbidden();

    expect(UserTask::where('user_id', $user->id)->where('task_id', $unrelated->id)->exists())->toBeFalse()
        ->and(UserTask::where('user_id', $user->id)->where('task_id', $visible->id)->exists())->toBeFalse();
});

test('a stale verified snapshot cannot authorize task progression', function () {
    $task = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);
    $case->increment('fact_version');

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
            'status' => 'done',
        ])
        ->assertForbidden();

    expect(UserTask::where('user_id', $user->id)->where('task_id', $task->id)->exists())->toBeFalse();
});

test('blocked dependencies and informational rules cannot be progressed', function () {
    $dependency = caseTaskApprovedRule('case.dependency', [['case_goal' => 'blue_card']]);
    $blocked = caseTaskApprovedRule('case.blocked', [['case_goal' => 'blue_card']], [
        'depends_on' => [$dependency->key],
    ]);
    $information = caseTaskApprovedRule('case.information', [['case_goal' => 'blue_card']], [
        'type' => 'info',
        'phase' => 'options',
    ]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);

    foreach ([$blocked, $information] as $task) {
        $this->actingAs($user)
            ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
                'status' => 'done',
            ])
            ->assertForbidden();
    }

    expect(UserTask::where('user_id', $user->id)->whereIn('task_id', [$blocked->id, $information->id])->exists())
        ->toBeFalse();
});

test('verified case tasks use the existing done and reopen lifecycle', function () {
    $this->travelTo('2026-08-04 10:00:00');
    $task = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
            'status' => 'done',
        ])
        ->assertRedirect();

    $userTask = UserTask::query()
        ->where('user_id', $user->id)
        ->where('task_id', $task->id)
        ->sole();

    expect($userTask->status)->toBe(TaskStatus::Done)
        ->and($userTask->completed_at?->toIso8601String())->toBe(now()->toIso8601String());

    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
            'status' => 'in_progress',
        ])
        ->assertRedirect();

    expect($userTask->fresh()->status)->toBe(TaskStatus::InProgress)
        ->and($userTask->fresh()->completed_at)->toBeNull()
        ->and($userTask->fresh()->next_due_at)->toBeNull();
});

test('progressing a currently verified task restores applicability', function () {
    $task = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);
    $userTask = UserTask::factory()->for($user)->for($task)->create([
        'is_applicable' => false,
        'status' => TaskStatus::NotStarted,
    ]);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
            'status' => 'done',
        ])
        ->assertRedirect();

    expect($userTask->fresh()->is_applicable)->toBeTrue()
        ->and($userTask->fresh()->status)->toBe(TaskStatus::Done);
});

test('completing a verified dependency moves the dependent task out of waiting', function () {
    $dependency = caseTaskApprovedRule('case.dependency', [['case_goal' => 'blue_card']]);
    caseTaskApprovedRule('case.dependent', [['case_goal' => 'blue_card']], [
        'depends_on' => ['case.dependency'],
    ]);
    [$user, $case] = caseTaskUser();
    $store = app(PlanSnapshotStore::class);
    $first = $store->store($case);

    expect(collect($first->sections['waiting'])->pluck('key')->all())->toContain('case.dependent');

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $dependency->key]), [
            'status' => 'done',
        ])
        ->assertRedirect();

    $second = $store->store($case);

    expect($second->id)->not->toBe($first->id)
        ->and(collect($second->sections['waiting'])->pluck('key')->all())->not->toContain('case.dependent')
        ->and(collect($second->sections['do_now'])->pluck('key')->all())->toContain('case.dependent');
});

test('case task progression rejects invalid lifecycle values', function () {
    $task = caseTaskApprovedRule('case.visible', [['case_goal' => 'blue_card']]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $task->key]), [
            'status' => 'cancelled',
        ])
        ->assertSessionHasErrors('status');

    expect(UserTask::where('user_id', $user->id)->where('task_id', $task->id)->exists())->toBeFalse();
});

test('reopening a dependency immediately invalidates the dependent task snapshot', function () {
    $dependency = caseTaskApprovedRule('case.dependency', [['case_goal' => 'blue_card']]);
    $dependent = caseTaskApprovedRule('case.dependent', [['case_goal' => 'blue_card']], [
        'depends_on' => [$dependency->key],
    ]);
    [$user, $case] = caseTaskUser();
    UserTask::factory()->for($user)->for($dependency)->create([
        'status' => TaskStatus::Done,
        'is_applicable' => true,
        'completed_at' => now(),
    ]);
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $dependency->key]), [
            'status' => 'not_started',
        ])
        ->assertRedirect();

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.update', ['task' => $dependent->key]), [
            'status' => 'done',
        ])
        ->assertForbidden();

    expect(UserTask::where('user_id', $user->id)->where('task_id', $dependent->id)->exists())->toBeFalse();
});

test('document checks are persisted only for authored documents on a verified task', function () {
    $task = caseTaskApprovedRule('case.documents', [['case_goal' => 'blue_card']], [
        'documents_required' => [
            'Valid passport',
            ['label' => 'Employment contract', 'note' => 'Bring the current signed version.'],
        ],
    ]);
    [$user, $case] = caseTaskUser();
    UserTask::factory()->for($user)->for($task)->create([
        'status' => TaskStatus::Submitted,
        'is_applicable' => false,
        'documents_checked' => [],
    ]);
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.documents.update', ['task' => $task->key]), [
            'documents_checked' => ['Employment contract', 'Invented document'],
        ])
        ->assertRedirect();

    $userTask = UserTask::query()
        ->where('user_id', $user->id)
        ->where('task_id', $task->id)
        ->sole();

    expect($userTask->documents_checked)->toBe(['Employment contract'])
        ->and($userTask->is_applicable)->toBeTrue()
        ->and($userTask->status)->toBe(TaskStatus::NotStarted);
});

test('documents cannot be changed for a task outside the active verified snapshot', function () {
    caseTaskApprovedRule('case.visible-documents', [['case_goal' => 'blue_card']]);
    $unrelated = caseTaskApprovedRule('case.hidden-documents', [['case_goal' => 'settlement_permit']], [
        'documents_required' => ['Passport'],
    ]);
    [$user, $case] = caseTaskUser();
    app(PlanSnapshotStore::class)->store($case);

    $this->actingAs($user)
        ->patch(route('bureaucracy.case-task.documents.update', ['task' => $unrelated->key]), [
            'documents_checked' => ['Passport'],
        ])
        ->assertForbidden();

    expect(UserTask::where('user_id', $user->id)->where('task_id', $unrelated->id)->exists())->toBeFalse();
});
