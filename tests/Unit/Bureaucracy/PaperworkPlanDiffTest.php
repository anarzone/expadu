<?php

use App\Bureaucracy\ReadModel\PaperworkPlanDiff;

/** A plan as PlanReadModel::for returns it, cut to what the diff reads. */
function diffPlan(array ...$processes): array
{
    return ['schema_version' => 'bureaucracy.plan.1', 'processes' => $processes];
}

function diffProcess(string $key, array $steps = [], string $workflow = 'preparing', array $extra = []): array
{
    return ['id' => 7, 'occurrence_key' => $key, 'title' => 'EU Blue Card', 'is_closed' => false,
        'state' => ['workflow' => $workflow], 'steps' => $steps, ...$extra];
}

function diffStep(string $id, string $status = 'todo', array $dates = []): array
{
    return ['step_id' => $id, 'title' => "Step {$id}", 'status' => $status, 'dates' => $dates];
}

test('🔒 an unchanged plan has nothing to say', function () {
    $plan = diffPlan(diffProcess('blue-card', [diffStep('apply', 'todo', [['kind' => 'legal_due', 'date' => '2027-02-12']])]));

    expect(PaperworkPlanDiff::between($plan, $plan))->toBe([]);
});

test('🔒 a task that now applies, and one that no longer does', function () {
    $before = diffPlan(diffProcess('registration'));
    $after = diffPlan(diffProcess('blue-card', extra: ['id' => null, 'title' => 'EU Blue Card']));

    expect(PaperworkPlanDiff::between($before, $after))->toBe([
        ['process_id' => null, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'added'],
        ['process_id' => 7, 'occurrence_key' => 'registration', 'title' => 'EU Blue Card', 'change' => 'removed'],
    ]);
});

test('🔒 a moved date says the plan\'s own dates, by step', function () {
    $before = diffPlan(diffProcess('blue-card', [diffStep('apply', 'todo', [['kind' => 'legal_due', 'date' => '2027-01-10'], ['kind' => 'document_expiry', 'date' => '2027-03-01']])]));
    $after = diffPlan(diffProcess('blue-card', [diffStep('apply', 'todo', [['kind' => 'legal_due', 'date' => '2027-02-12'], ['kind' => 'document_expiry', 'date' => '2027-04-01']])]));

    // A document's expiry is a fact, not the step's date: only the step's own date counts.
    expect(PaperworkPlanDiff::between($before, $after))->toBe([
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'due_moved',
            'step' => ['id' => 'apply', 'title' => 'Step apply'], 'from' => '2027-01-10', 'to' => '2027-02-12'],
    ]);
});

test('🔒 steps added, removed and ticked, and the task\'s own state', function () {
    $before = diffPlan(diffProcess('blue-card', [diffStep('gather'), diffStep('book')], 'preparing'));
    $after = diffPlan(diffProcess('blue-card', [diffStep('gather', 'completed'), diffStep('apply')], 'action_required'));

    expect(PaperworkPlanDiff::between($before, $after))->toBe([
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'state_changed', 'from' => 'preparing', 'to' => 'action_required'],
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'state_changed',
            'step' => ['id' => 'gather', 'title' => 'Step gather'], 'from' => 'todo', 'to' => 'completed'],
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'step_added',
            'step' => ['id' => 'apply', 'title' => 'Step apply']],
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'step_removed',
            'step' => ['id' => 'book', 'title' => 'Step book']],
    ]);
});

test('🔒 closed tasks are left out; a task closing reads as removed from the open plan', function () {
    $before = diffPlan(diffProcess('blue-card'), diffProcess('old', extra: ['is_closed' => true]));
    $after = diffPlan(diffProcess('blue-card', extra: ['is_closed' => true]), diffProcess('old', extra: ['is_closed' => true]));

    expect(PaperworkPlanDiff::between($before, $after))->toBe([
        ['process_id' => 7, 'occurrence_key' => 'blue-card', 'title' => 'EU Blue Card', 'change' => 'removed'],
    ]);
});

test('🔒 rows it can\'t read are skipped, never guessed', function () {
    $before = diffPlan(['title' => 'No key'], diffProcess('blue-card', [['title' => 'No id'], diffStep('apply', 'todo', [['kind' => 'legal_due', 'date' => null]])]));
    $after = diffPlan(diffProcess('blue-card', [diffStep('apply', 'todo', [['kind' => 'legal_due', 'date' => '2027-02-12']])]));

    // A date that wasn't known before isn't a move.
    expect(PaperworkPlanDiff::between($before, $after))->toBe([]);
});
