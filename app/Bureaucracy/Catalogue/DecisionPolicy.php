<?php

namespace App\Bureaucracy\Catalogue;

use App\Models\Task;
use DomainException;

/** Reviewed decision settings travel with the immutable compiled rule, not UI defaults. */
final class DecisionPolicy
{
    public function compile(Task $task, array $mapping, array $criterionKeys): array
    {
        $result = [];
        if (array_key_exists('action_requires_intent', $mapping)) {
            $groups = $task->applies_if ?? [];
            $groups = array_is_list($groups) ? $groups : [$groups];
            if (! is_bool($mapping['action_requires_intent'])
                || ! in_array($mapping['kind'] ?? null, ['preparation', 'action'], true)
                || $groups === []
                || count(array_filter($groups, fn ($group) => isset($group['case_goal']))) !== count($groups)) {
                throw new DomainException('Intent-gated actions require a goal in every reviewed alternative.');
            }
            $result['action_requires_intent'] = $mapping['action_requires_intent'];
        }
        if (array_key_exists('relevance_keys', $mapping)) {
            $keys = $mapping['relevance_keys'];
            if (! is_array($keys) || ! array_is_list($keys)
                || count(array_filter($keys, is_string(...))) !== count($keys)
                || count(array_unique($keys)) !== count($keys)
                || array_diff($keys, $criterionKeys) !== [] || in_array('case_goal', $keys, true)) {
                throw new DomainException('Relevance requires unique criterion keys, excluding goal-only preferences.');
            }
            $result['relevance_keys'] = $keys;
        }
        if (array_key_exists('temporal_policy', $mapping)) {
            $policy = $mapping['temporal_policy'];
            if (! is_array($policy)
                || array_diff(array_keys($policy), ['kind', 'version', 'content_version', 'reviewed_by', 'verified_at', 'source_url']) !== []
                || ! in_array($policy['kind'] ?? null, ['legal_due', 'preparation_target', 'authority_follow_up'], true)
                || ! is_string($policy['version'] ?? null) || ! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,99}\z/', $policy['version'])
                || ($policy['content_version'] ?? null) !== $task->content_version
                || ($policy['reviewed_by'] ?? null) !== $task->reviewed_by
                || ($policy['verified_at'] ?? null) !== $task->verified_at?->toDateString()
                || ($task->deadline_type?->value ?? 'none') === 'none') {
                throw new DomainException('Temporal policy requires a defined clock and version-bound source review.');
            }
            $sources = array_filter($task->legal_sources ?? [], fn ($source) => ($source['url'] ?? null) === ($policy['source_url'] ?? null)
                && ($policy['kind'] !== 'legal_due' || ($source['kind'] ?? null) === 'primary'));
            if ($sources === []) {
                throw new DomainException('Temporal policy must cite the reviewed unit; legal deadlines require its primary source.');
            }
            $result['temporal_policy'] = $policy;
        }

        return $result;
    }
}
