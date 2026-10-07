<?php

namespace App\Bureaucracy\Processes;

use DomainException;

/** User-reported workflow, never a determination of legal status or document validity. */
final class ProcessStateMachine
{
    public const Events = ['preparation_started', 'step_completed', 'step_reopened', 'submission_recorded',
        'submission_retracted', 'blocked_reported', 'waiting_reported', 'action_required_reported', 'completion_reported',
        'cancellation_reported', 'process_reopened', 'process_untracked', 'appointment_recorded', 'appointment_cancelled'];

    /** Workflow reports that carry the person's own status details (date, note) into `state.report`. */
    public const WorkflowReports = ['preparation_started', 'submission_recorded', 'submission_retracted', 'blocked_reported',
        'waiting_reported', 'action_required_reported', 'completion_reported', 'cancellation_reported', 'process_reopened', 'process_untracked'];

    /** States in which the person's last explicit report survives checkbox changes. */
    private const ReportedStates = ['blocked', 'submitted', 'waiting_authority', 'action_required'];

    private const SubmissionStates = ['submitted', 'waiting_authority', 'action_required'];

    private const Transitions = [
        'preparation_started' => [['not_started', 'preparing', 'blocked', 'action_required'], 'preparing'],
        'blocked_reported' => [['not_started', 'preparing', 'blocked'], 'blocked'],
        'submission_recorded' => [['not_started', 'preparing', 'blocked', 'submitted', 'waiting_authority', 'action_required'], 'submitted'],
        'waiting_reported' => [['submitted', 'waiting_authority'], 'waiting_authority'],
        'action_required_reported' => [['submitted', 'waiting_authority', 'preparing'], 'action_required'],
        'completion_reported' => [['preparing', 'submitted', 'waiting_authority', 'action_required'], 'completed'],
        'cancellation_reported' => [['not_started', 'preparing', 'blocked', 'submitted', 'waiting_authority', 'action_required'], 'cancelled'],
        'process_reopened' => [['completed', 'cancelled'], 'preparing'],
        'process_untracked' => [['not_started'], 'untracked'],
    ];

    public function initial(array $steps): array
    {
        $states = [];
        foreach ($steps as $step) {
            if (isset($states[$step['id']])) {
                throw new DomainException('Duplicate process step identity.');
            }
            $states[$step['id']] = empty($step['depends_on']) ? 'todo' : 'blocked';
        }

        return ['workflow' => 'not_started', 'steps' => $states, 'completion_basis' => null, 'report' => null];
    }

    /**
     * @param  list<array{id: int, type: string, payload: array}>  $history  active (correction-resolved) events, needed to retract a submission
     */
    public function apply(array $state, string $event, array $payload, array $steps, array $external = [], array $history = []): array
    {
        if (! in_array($event, self::Events, true)) {
            throw new DomainException('Unsupported workflow event.');
        }
        if (($state['workflow'] ?? null) === 'untracked') {
            throw new DomainException('Start tracking this process again before reporting progress.');
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
            if (! in_array($state['workflow'], self::ReportedStates, true)) {
                if ($state['workflow'] !== 'preparing') {
                    $state['report'] = null;
                }
                $state['workflow'] = 'preparing';
                $state['completion_basis'] = null;
            }

            return $this->withPrerequisites($state, $steps, $external);
        }
        if ($event === 'submission_retracted') {
            if (! in_array($state['workflow'], self::SubmissionStates, true)) {
                throw new DomainException('Only a reported submission that is still open can be withdrawn.');
            }
            $state['workflow'] = $this->workflowWithout($history, (int) ($payload['event_id'] ?? 0));
        } else {
            [$allowed, $target] = self::Transitions[$event];
            if (! in_array($state['workflow'], $allowed, true)) {
                throw new DomainException($this->refusal($event));
            }
            if ($event === 'completion_reported' && array_filter($state['steps'], fn ($status) => $status !== 'completed') !== []) {
                throw new DomainException('Confirm the individual steps before completing this process.');
            }
            if ($event === 'process_untracked' && in_array('completed', $state['steps'], true)) {
                throw new DomainException('This process already has recorded progress. Report it as cancelled instead.');
            }
            $state['workflow'] = $target;
        }
        $state['completion_basis'] = $state['workflow'] === 'completed' ? 'user_report' : null;
        // The person's own words and date for this status. Unknown stays null; nothing is inferred.
        $state['report'] = ['event' => $event, 'occurred_on' => $payload['occurred_on'] ?? null, 'note' => $payload['note'] ?? null];

        return $state;
    }

    /**
     * Workflow as reported without the withdrawn submission(s). Reports that only made sense
     * after a withdrawn submission (for example "waiting for the authority") drop out with it.
     */
    private function workflowWithout(array $history, int $retracted): string
    {
        $withdrawn = [$retracted => true];
        foreach ($history as $event) {
            if ($event['type'] === 'submission_retracted') {
                $withdrawn[(int) ($event['payload']['event_id'] ?? 0)] = true;
            }
        }
        $workflow = 'not_started';
        foreach ($history as $event) {
            $type = $event['type'];
            if (isset($withdrawn[$event['id']]) || in_array($type, ['submission_retracted', 'process_started', 'appointment_recorded', 'appointment_cancelled'], true)) {
                continue;
            }
            if (in_array($type, ['step_completed', 'step_reopened'], true)) {
                if (in_array($workflow, ['completed', 'cancelled'], true)) {
                    $workflow = $type === 'step_reopened' ? 'preparing' : $workflow;
                } elseif (! in_array($workflow, self::ReportedStates, true)) {
                    $workflow = 'preparing';
                }

                continue;
            }
            if ($type === 'process_untracked') {
                $workflow = 'not_started'; // A later start resumes from a clean, not-started workflow.

                continue;
            }
            [$allowed, $target] = self::Transitions[$type] ?? [[], null];
            if ($target !== null && in_array($workflow, $allowed, true)) {
                $workflow = $target;
            }
        }

        return $workflow;
    }

    private function refusal(string $event): string
    {
        return match ($event) {
            'waiting_reported' => 'Record the submission first. Waiting for the authority follows a reported submission.',
            'blocked_reported' => 'You can mark a process as blocked while preparing it. After submitting, report that the authority needs something instead.',
            'completion_reported' => 'Start or submit this process before reporting it as completed.',
            'process_reopened' => 'Only a completed or cancelled process can be reopened.',
            'process_untracked' => 'This process already has recorded progress. Report it as cancelled instead.',
            'action_required_reported' => 'Report that something is needed while preparing or after submitting.',
            default => 'This event does not follow the recorded workflow.',
        };
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
