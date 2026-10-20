<?php

use App\Bureaucracy\Processes\ProcessStateMachine;
use App\Bureaucracy\Processes\ProgressSummary;

test('submission waiting and blocked preparation are separate workflow states', function () {
    $machine = new ProcessStateMachine;
    $steps = [['id' => 'prepare', 'depends_on' => []], ['id' => 'submit', 'depends_on' => ['prepare']]];
    $initial = $machine->initial($steps);
    expect($initial['steps']['submit'])->toBe('blocked');
    expect(fn () => $machine->apply($initial, 'step_completed', ['step_id' => 'submit'], $steps))->toThrow(DomainException::class);
    $prepared = $machine->apply($initial, 'step_completed', ['step_id' => 'prepare'], $steps);
    expect($prepared['steps']['submit'])->toBe('todo');
    $submitted = $machine->apply($prepared, 'submission_recorded', [], $steps);
    expect($submitted['workflow'])->toBe('submitted');
    $waiting = $machine->apply($submitted, 'waiting_reported', [], $steps);
    expect($waiting['workflow'])->toBe('waiting_authority');
    expect($machine->apply($waiting, 'action_required_reported', [], $steps)['workflow'])->toBe('action_required');
});

test('a reported completion never creates an authority decision and explicit reopen retains the step identity', function () {
    $machine = new ProcessStateMachine;
    $steps = [['id' => 'prepare', 'depends_on' => []]];
    $done = $machine->apply($machine->initial($steps), 'step_completed', ['step_id' => 'prepare'], $steps);
    $done = $machine->apply($done, 'completion_reported', [], $steps);
    expect($done['workflow'])->toBe('completed')->and($done['completion_basis'])->toBe('user_report')
        ->and($done)->not->toHaveKey('authority_decision');
    $again = $machine->apply($done, 'step_reopened', ['step_id' => 'prepare'], $steps);
    expect($again['workflow'])->toBe('preparing')->and(array_keys($again['steps']))->toBe(['prepare']);
    expect(fn () => $machine->apply($again, 'authority_approval_verified', [], $steps))->toThrow(DomainException::class);
});

test('finishing preparation neither closes the process nor prevents reporting submission', function () {
    $machine = new ProcessStateMachine;
    $steps = [['id' => 'prepare', 'depends_on' => []]];
    $ready = $machine->apply($machine->initial($steps), 'step_completed', ['step_id' => 'prepare'], $steps);
    expect($ready['workflow'])->toBe('preparing')->and($ready['completion_basis'])->toBeNull();
    expect($machine->apply($ready, 'submission_recorded', [], $steps)['workflow'])->toBe('submitted');
});

test('checkbox changes preserve a separately reported submission or waiting state', function (string $workflow) {
    $machine = new ProcessStateMachine;
    $steps = [['id' => 'prepare', 'depends_on' => []]];
    $state = [...$machine->initial($steps), 'workflow' => $workflow];
    $done = $machine->apply($state, 'step_completed', ['step_id' => 'prepare'], $steps);
    expect($done['workflow'])->toBe($workflow);
    expect($machine->apply($done, 'step_reopened', ['step_id' => 'prepare'], $steps)['workflow'])->toBe($workflow);
})->with(['submitted', 'waiting_authority', 'action_required']);

test('an appointment cannot alter workflow completion or stand in for submission', function () {
    $machine = new ProcessStateMachine;
    $steps = [['id' => 'prepare', 'depends_on' => []]];
    $initial = $machine->initial($steps);
    expect($machine->apply($initial, 'appointment_recorded', [], $steps))->toBe($initial);
    expect(fn () => $machine->apply($initial, 'waiting_reported', [], $steps))->toThrow(DomainException::class);
});

test('progress totals contain unique reachable steps and disjoint buckets across occurrences', function () {
    $summary = (new ProgressSummary)->for([
        ['id' => 1, 'state' => ['workflow' => 'preparing', 'steps' => ['prepare' => 'completed', 'submit' => 'todo']]],
        ['id' => 2, 'state' => ['workflow' => 'waiting_authority', 'steps' => ['prepare' => 'completed', 'submit' => 'todo']]],
        ['id' => 3, 'state' => ['workflow' => 'cancelled', 'steps' => ['prepare' => 'todo']]],
    ]);
    expect($summary['total'])->toBe(4)->and($summary['completed']['ids'])->toBe(['1:prepare', '2:prepare'])
        ->and($summary['todo']['ids'])->toBe(['1:submit'])->and($summary['waiting']['ids'])->toBe(['2:submit'])
        ->and($summary['completed']['count'] + $summary['todo']['count'] + $summary['waiting']['count'] + $summary['blocked']['count'])->toBe(4);
    expect((new ProgressSummary)->for([])['total'])->toBe(0);
});
