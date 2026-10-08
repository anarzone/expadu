<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Processes\ProcessHistory;
use App\Bureaucracy\Processes\ProcessStateMachine;
use App\Bureaucracy\Processes\UntrackEligibility;

/**
 * Presentation fields for one process projection. Everything is copied or counted from the
 * reviewed guidance, the person's own reports and their own paperwork state; nothing here
 * decides applicability, legal status or dates.
 */
final class ProcessDetails
{
    /**
     * @param  list<array>  $timeline  plan timeline rows (already carrying action ids)
     * @param  list<array>|null  $requirements  paperwork requirement rows, or null without evidence access
     * @param  list<array>  $events  stored process events with payloads
     */
    /** @param array<int, true> $withRecords process ids that hold requirement uses or evidence shares */
    public function for(array $process, CatalogueOrder $order, array $timeline, ?array $requirements, array $events, array $withRecords = []): array
    {
        $current = $process['guidance'] !== [];
        // The process name, then (for releases without one) the first step's reviewed title.
        $title = $order->processTitle($process['definition_id'])
            ?? ($current ? $order->primaryTitle($process['guidance']) : $order->definitionTitle($process['definition_id']));
        $steps = $current ? $this->currentSteps($process, $order, $timeline, $requirements) : $this->retainedSteps($process, $order);
        $closure = $this->closure($process, $events);

        $types = array_column(array_filter($events, fn ($event) => $process['id'] !== null && $event['process_id'] === $process['id']), 'type');

        return [...$process, 'title' => $title, 'topic_label' => $this->topicLabel($process['topic'] ?? null), 'steps' => $steps, ...$closure,
            'blocking_reason' => $this->blockingReason($process, $steps, $requirements),
            // Whether "Track task" can still be undone with process_untracked (same rule the command enforces).
            'untrackable' => $process['id'] !== null && UntrackEligibility::allows($process['state'], $types, isset($withRecords[$process['id']])),
            // The progress changes accepted from the current state (only once tracking has started).
            'progress_options' => $process['id'] === null ? [] : (new ProcessStateMachine)->progressOptions($process['state'])];
    }

    public function topicLabel(?string $topic): ?string
    {
        return $topic === null ? null : config('bureaucracy_catalogue.topic_labels.'.$topic);
    }

    private function currentSteps(array $process, CatalogueOrder $order, array $timeline, ?array $requirements): array
    {
        $prefix = ($process['id'] ?? $process['occurrence_key']).':';
        $definitions = array_column($process['current_steps'], null, 'id');
        $steps = [];
        foreach ($process['guidance'] as $guidance) {
            $stepId = $guidance['step_id'];
            if (! isset($definitions[$stepId])) {
                continue;
            }
            $id = $prefix.$stepId;
            $rows = $requirements === null ? null : array_values(array_filter($requirements, fn ($row) => $row['occurrence_key'] === $process['occurrence_key']
                && $row['source_rule_id'] === $guidance['id'] && $row['applicability'] !== 'not_required'));
            $open = $rows === null ? null : (array_values(array_filter($rows, fn ($row) => $row['readiness'] !== 'confirmed_for_use'))[0] ?? null);
            $review = $guidance['review'] ?? [];
            $steps[] = ['id' => $id, 'step_id' => $stepId, 'guidance_id' => $guidance['id'], 'title' => $guidance['title'],
                'description' => $guidance['description'], 'status' => $this->status($process['state'], $stepId), 'step_state' => $process['state']['steps'][$stepId] ?? null,
                'position' => $order->position($guidance['id']), 'depends_on' => $definitions[$stepId]['depends_on'] ?? [],
                'verified_at' => $review['verified_at'] ?? null, 'review_due_at' => $review['review_due_at'] ?? null,
                'content_version' => $review['content_version'] ?? null,
                'sources' => ['official' => array_map(fn ($action) => ['id' => $action['id'], 'url' => $action['url'], 'purpose' => $action['purpose']], $guidance['actions'] ?? []),
                    'legal' => array_values(array_map(fn ($source) => ['kind' => $source['kind'] ?? null, 'label' => $source['label'] ?? null, 'url' => $source['url'] ?? null],
                        array_filter($review['legal_sources'] ?? [], 'is_array')))],
                'requirements' => $rows === null ? null : ['ready' => count(array_filter($rows, fn ($row) => $row['readiness'] === 'confirmed_for_use')), 'total' => count($rows)],
                'first_open_requirement' => $open === null ? null : ['id' => $open['id'], 'label' => $open['label'], 'readiness' => $open['readiness'],
                    'applicability' => $open['applicability'], 'conditional' => $open['applicability'] !== 'required'],
                'dates' => array_values(array_filter($timeline, fn ($row) => ($row['action_id'] ?? null) === $id))];
        }
        usort($steps, fn ($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));

        return $steps;
    }

    /** Retained work keeps its recorded step states; titles appear only while the unit is still reviewed. */
    private function retainedSteps(array $process, CatalogueOrder $order): array
    {
        $prefix = ($process['id'] ?? $process['occurrence_key']).':';
        $variants = $order->variantsByStep($process['definition_id']);
        $steps = [];
        foreach ($process['state']['steps'] as $stepId => $state) {
            $variant = $variants[$stepId] ?? null;
            $steps[] = ['id' => $prefix.$stepId, 'step_id' => $stepId, 'guidance_id' => $variant['id'] ?? null, 'title' => $variant['title'] ?? null,
                'description' => null, 'status' => $this->status($process['state'], $stepId), 'step_state' => $state,
                'position' => $variant === null ? null : $order->position($variant['id']), 'depends_on' => [],
                'verified_at' => null, 'review_due_at' => null, 'content_version' => null, 'sources' => ['official' => [], 'legal' => []],
                'requirements' => null, 'first_open_requirement' => null, 'dates' => []];
        }
        usort($steps, fn ($a, $b) => ($a['position'] ?? PHP_INT_MAX) <=> ($b['position'] ?? PHP_INT_MAX));

        return $steps;
    }

    /** Same buckets as ProgressSummary, so step rows and progress ids never disagree. */
    private function status(array $state, string $stepId): string
    {
        if ($state['workflow'] === 'cancelled') {
            return 'cancelled';
        }

        return match ($state['steps'][$stepId] ?? 'todo') {
            'completed' => 'completed',
            'blocked' => 'blocked',
            default => in_array($state['workflow'], ['submitted', 'waiting_authority'], true) ? 'waiting' : 'todo',
        };
    }

    /** Closed state and date come only from the person's own completion/cancellation report. */
    private function closure(array $process, array $events): array
    {
        $workflow = $process['state']['workflow'];
        $closed = in_array($workflow, ['completed', 'cancelled'], true);
        $report = null;
        if ($closed && $process['id'] !== null) {
            $type = $workflow === 'completed' ? 'completion_reported' : 'cancellation_reported';
            $active = (new ProcessHistory)->active(array_values(array_filter($events, fn ($event) => $event['process_id'] === $process['id'])));
            foreach ($active as $event) {
                if ($event['type'] === $type && ($report === null || $event['revision_event_id'] > $report['revision_event_id'])) {
                    $report = $event;
                }
            }
        }

        return ['is_closed' => $closed, 'closed_on' => $report['payload']['occurred_on'] ?? null, 'closed_event_id' => $report['id'] ?? null];
    }

    /**
     * Why a process reported as needing action may be stuck, from the person's own paperwork only:
     * the first requirement (in step order) that is not ready. Never a legal reason.
     */
    private function blockingReason(array $process, array $steps, ?array $requirements): ?array
    {
        if ($requirements === null || ! in_array($process['state']['workflow'], ['action_required', 'blocked'], true)) {
            return null;
        }
        foreach ($steps as $step) {
            foreach ($requirements as $row) {
                if ($row['occurrence_key'] === $process['occurrence_key'] && $row['source_rule_id'] === $step['guidance_id']
                    && $row['applicability'] !== 'not_required' && in_array($row['readiness'], ['missing', 'needs_reconfirmation'], true)) {
                    return ['basis' => 'requirement_readiness', 'step_id' => $step['step_id'], 'requirement_id' => $row['id'],
                        'label' => $row['label'], 'readiness' => $row['readiness'], 'applicability' => $row['applicability']];
                }
            }
        }

        return null;
    }
}
