<?php

namespace App\Bureaucracy\Processes;

use DomainException;

/** User-reported workflow, never a determination of legal status or document validity. */
final class ProcessStateMachine
{
    public const Events = ['preparation_started', 'step_completed', 'step_reopened', 'submission_recorded',
        'waiting_reported', 'action_required_reported', 'completion_reported', 'cancellation_reported',
        'process_reopened', 'appointment_recorded', 'appointment_cancelled'];

    public function initial(array $steps): array
    {
        $states = [];
        foreach ($steps as $step) {
            if (isset($states[$step['id']])) {
                throw new DomainException('Duplicate process step identity.');
            }
            $states[$step['id']] = empty($step['depends_on']) ? 'todo' : 'blocked';
        }

        return ['workflow' => 'not_started', 'steps' => $states, 'completion_basis' => null];
    }

    public function apply(array $state, string $event, array $payload, array $steps, array $external = []): array
    {
        if (! in_array($event, self::Events, true)) {
            throw new DomainException('Unsupported workflow event.');
        }
        if (in_array($event, ['appointment_recorded', 'appointment_cancelled'], true)) {
            return $state;
        }
        $state = $this->withPrerequisites($state, $steps, $external);
        if (in_array($state['workflow'], ['completed', 'cancelled'], true) && ! in_array($event, ['step_reopened', 'process_reopened'], true)) {
            throw new DomainException('Reopen this process explicitly before reporting more work.');
        }
        if (in_array($event, ['step_completed', 'step_reopened'], true)) {
            $key = $payload['step_id'] ?? null;
            if (! is_string($key) || ! array_key_exists($key, $state['steps'])) {
                throw new DomainException('Choose a step in this process occurrence.');
            }
            if ($event === 'step_completed' && $state['steps'][$key] === 'blocked') {
                throw new DomainException('The recorded prerequisite is not complete.');
            }
            $state['steps'][$key] = $event === 'step_completed' ? 'completed' : 'todo';
            if (! in_array($state['workflow'], ['submitted', 'waiting_authority', 'action_required'], true)) {
                $state['workflow'] = 'preparing';
                $state['completion_basis'] = null;
            }

            return $this->withPrerequisites($state, $steps, $external);
        }
        $allowed = match ($event) {
            'preparation_started' => ['not_started', 'preparing', 'blocked', 'action_required'],
            'submission_recorded' => ['not_started', 'preparing', 'submitted', 'waiting_authority', 'action_required'],
            'waiting_reported' => ['submitted', 'waiting_authority'],
            'action_required_reported' => ['submitted', 'waiting_authority', 'preparing'],
            'completion_reported' => ['preparing', 'submitted', 'waiting_authority', 'action_required'],
            'cancellation_reported' => ['not_started', 'preparing', 'blocked', 'submitted', 'waiting_authority', 'action_required'],
            'process_reopened' => ['completed', 'cancelled'],
        };
        if (! in_array($state['workflow'], $allowed, true)) {
            throw new DomainException('This event does not follow the recorded workflow.');
        }
        if ($event === 'completion_reported' && array_filter($state['steps'], fn ($status) => $status !== 'completed') !== []) {
            throw new DomainException('Confirm the individual steps before completing this process.');
        }
        $state['workflow'] = match ($event) {
            'preparation_started', 'process_reopened' => 'preparing',
            'submission_recorded' => 'submitted',
            'waiting_reported' => 'waiting_authority',
            'action_required_reported' => 'action_required',
            'completion_reported' => 'completed',
            'cancellation_reported' => 'cancelled',
        };
        $state['completion_basis'] = $state['workflow'] === 'completed' ? 'user_report' : null;

        return $state;
    }

    public function withPrerequisites(array $state, array $steps, array $external = []): array
    {
        foreach ($steps as $step) {
            if (($state['steps'][$step['id']] ?? null) === 'completed') {
                continue;
            }
            $blocked = array_filter($step['depends_on'], fn ($dependency) => ($state['steps'][$dependency] ?? $external[$dependency] ?? null) !== 'completed');
            $state['steps'][$step['id']] = $blocked === [] ? 'todo' : 'blocked';
        }

        return $state;
    }
}
