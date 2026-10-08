<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Assessment\EvaluateCriteria;
use Carbon\CarbonImmutable;

final class ReviewedGuidance
{
    /** Existing approved prose only; ambiguous branch text stays explicitly conditional. */
    public function for(array $variant, array $facts, CarbonImmutable $at): array
    {
        $evaluate = new EvaluateCriteria;
        $instructions = [];
        foreach ($variant['instructions'] as $instruction) {
            $condition = $evaluate->evaluate($instruction['applies_if'] ?? [], $facts, $at);
            if ($condition['status'] === 'unmet') {
                continue;
            }
            $conditional = $condition['status'] !== 'met' || (isset($instruction['branch']) && empty($instruction['applies_if']));
            $instructions[] = [...array_intersect_key($instruction, array_flip(['id', 'title', 'body', 'description', 'branch', 'action_id', 'note'])),
                'applicability' => $conditional ? 'conditional' : 'applies', 'missing_facts' => $condition['missing']];
        }
        $description = $variant['description'];
        $additions = [];
        foreach ($variant['description_variants'] as $alternative) {
            $condition = $evaluate->evaluate($alternative['applies_if'] ?? [], $facts, $at);
            if ($condition['status'] === 'unmet' || ! is_string($alternative['body'] ?? null) || trim($alternative['body']) === '') {
                continue;
            }
            $body = trim($alternative['body']);
            $additions[] = ['body' => $body, 'applicability' => $condition['status'] === 'met' ? 'applies' : 'conditional', 'missing_facts' => $condition['missing']];
            if ($condition['status'] === 'met') {
                $description = implode("\n\n", array_filter([trim((string) $description), $body]));
            }
        }

        return ['id' => $variant['id'], 'step_id' => $variant['step_id'], 'title' => $variant['title'], 'description' => $description,
            'description_additions' => $additions,
            'kind' => $variant['kind'], 'type' => $variant['type'], 'assessment' => $variant['assessment'],
            // Reviewed action steps are finished by reporting the submission; every other kind by completing the step.
            'completion_event' => $variant['kind'] === 'action' ? 'submission_recorded' : 'step_completed',
            'actionable' => $variant['actionable'], 'coverage' => $variant['coverage'], 'criteria' => $variant['criteria'],
            'missing_facts' => $variant['missing_facts'], 'instructions' => $instructions,
            'actions' => $variant['actions'], 'unavailable_actions' => $variant['unavailable_actions'],
            // Documents this step produces that other published steps need (catalogue links, not the person's progress).
            'produces' => $variant['produces'] ?? [],
            'source_hash' => $variant['source_hash'], 'review' => $variant['review']];
    }
}
