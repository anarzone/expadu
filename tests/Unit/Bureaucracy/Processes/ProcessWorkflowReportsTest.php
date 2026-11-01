<?php

use App\Bureaucracy\Processes\ProcessStateMachine;

beforeEach(function () {
    $this->machine = new ProcessStateMachine;
    $this->steps = [['id' => 'prepare', 'depends_on' => []], ['id' => 'submit', 'depends_on' => ['prepare']]];
    $this->initial = $this->machine->initial($this->steps);
});

test('a person can report being blocked while preparing and resume explicitly, keeping their own note', function () {
    $blocked = $this->machine->apply($this->initial, 'blocked_reported', ['note' => 'Waiting for my landlord letter'], $this->steps);
    expect($blocked['workflow'])->toBe('blocked')
        ->and($blocked['report'])->toBe(['event' => 'blocked_reported', 'occurred_on' => null, 'note' => 'Waiting for my landlord letter']);
    $ticked = $this->machine->apply($blocked, 'step_completed', ['step_id' => 'prepare'], $this->steps);
    expect($ticked['workflow'])->toBe('blocked')->and($ticked['report']['note'])->toBe('Waiting for my landlord letter');
    $resumed = $this->machine->apply($ticked, 'preparation_started', [], $this->steps);
    expect($resumed['workflow'])->toBe('preparing')->and($resumed['report'])->toBe(['event' => 'preparation_started', 'occurred_on' => null, 'note' => null]);
});

test('blocked is a preparation state and waiting needs a reported submission', function () {
    $submitted = $this->machine->apply($this->initial, 'submission_recorded', ['occurred_on' => '2026-09-01'], $this->steps);
    expect(fn () => $this->machine->apply($submitted, 'blocked_reported', [], $this->steps))
        ->toThrow(DomainException::class, 'report that the authority needs something');
    expect(fn () => $this->machine->apply($this->initial, 'waiting_reported', [], $this->steps))
        ->toThrow(DomainException::class, 'Record the submission first');
    expect($this->machine->apply($submitted, 'waiting_reported', [], $this->steps)['workflow'])->toBe('waiting_authority');
});

test('completion still needs every step and a cancellation keeps its reported close date', function () {
    $preparing = $this->machine->apply($this->initial, 'preparation_started', [], $this->steps);
    expect(fn () => $this->machine->apply($preparing, 'completion_reported', ['occurred_on' => '2026-09-01'], $this->steps))
        ->toThrow(DomainException::class, 'Confirm the individual steps');
    $cancelled = $this->machine->apply($preparing, 'cancellation_reported', ['occurred_on' => '2026-09-02', 'note' => 'Moved away'], $this->steps);
    expect($cancelled['workflow'])->toBe('cancelled')->and($cancelled['completion_basis'])->toBeNull()
        ->and($cancelled['report'])->toBe(['event' => 'cancellation_reported', 'occurred_on' => '2026-09-02', 'note' => 'Moved away']);
});

test('withdrawing a mistaken submission returns to the reported workflow before it and drops waiting that followed it', function () {
    $history = [
        ['id' => 1, 'type' => 'process_started', 'payload' => []],
        ['id' => 2, 'type' => 'blocked_reported', 'payload' => []],
        ['id' => 3, 'type' => 'submission_recorded', 'payload' => ['occurred_on' => '2026-09-01']],
        ['id' => 4, 'type' => 'waiting_reported', 'payload' => []],
    ];
    $state = [...$this->initial, 'workflow' => 'waiting_authority'];
    $retracted = $this->machine->apply($state, 'submission_retracted', ['event_id' => 3], $this->steps, [], $history);
    expect($retracted['workflow'])->toBe('blocked')->and($retracted['report']['event'])->toBe('submission_retracted')
        ->and($retracted['steps'])->toBe($state['steps']);
});

test('withdrawing one of two submissions keeps the workflow that the other submission supports', function () {
    $history = [
        ['id' => 1, 'type' => 'submission_recorded', 'payload' => []],
        ['id' => 2, 'type' => 'action_required_reported', 'payload' => []],
        ['id' => 3, 'type' => 'submission_recorded', 'payload' => []],
    ];
    $state = [...$this->initial, 'workflow' => 'submitted'];
    expect($this->machine->apply($state, 'submission_retracted', ['event_id' => 3], $this->steps, [], $history)['workflow'])->toBe('action_required');
    expect($this->machine->apply($state, 'submission_retracted', ['event_id' => 1], $this->steps, [], $history)['workflow'])->toBe('submitted');
});

test('a submission cannot be withdrawn after the process is closed or before anything was submitted', function () {
    expect(fn () => $this->machine->apply($this->initial, 'submission_retracted', ['event_id' => 1], $this->steps))
        ->toThrow(DomainException::class, 'still open');
    expect(fn () => $this->machine->apply([...$this->initial, 'workflow' => 'cancelled'], 'submission_retracted', ['event_id' => 1], $this->steps))
        ->toThrow(DomainException::class, 'Reopen');
});

test('untracking is only possible before progress and blocks every later report', function () {
    $untracked = $this->machine->apply($this->initial, 'process_untracked', [], $this->steps);
    expect($untracked['workflow'])->toBe('untracked');
    foreach (['appointment_recorded', 'preparation_started', 'step_completed'] as $event) {
        expect(fn () => $this->machine->apply($untracked, $event, ['step_id' => 'prepare'], $this->steps))->toThrow(DomainException::class, 'Start tracking');
    }
    $preparing = $this->machine->apply($this->initial, 'step_completed', ['step_id' => 'prepare'], $this->steps);
    expect(fn () => $this->machine->apply($preparing, 'process_untracked', [], $this->steps))->toThrow(DomainException::class, 'cancelled instead');
});
