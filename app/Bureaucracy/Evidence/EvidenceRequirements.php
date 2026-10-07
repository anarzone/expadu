<?php

namespace App\Bureaucracy\Evidence;

use App\Bureaucracy\Assessment\EvaluateCriteria;
use App\Bureaucracy\Catalogue\CatalogueHash;
use Carbon\CarbonImmutable;

final class EvidenceRequirements
{
    /** Pure projection; a route label is not proof that its documents apply. */
    public function for(array $variants, array $facts, CarbonImmutable $at): array
    {
        $criteria = new EvaluateCriteria;
        $result = [];
        foreach ($variants as $variant) {
            $review = $variant['review'];
            if (($review['review_status'] ?? null) !== 'approved' || ($review['review_due_at'] ?? '') < $at->toDateString()
                || ($review['effective_from'] ?? '0000-01-01') > $at->toDateString()
                || ($review['effective_to'] ?? '9999-12-31') < $at->toDateString()) {
                continue;
            }
            $scope = $criteria->evaluate($variant['conditions'], $facts, $at);
            foreach ($variant['documents'] as $document) {
                $condition = $criteria->evaluate($document['applies_if'] ?? [], $facts, $at);
                $branchGroups = [];
                $branch = ['status' => 'met', 'missing' => []];
                if (isset($document['branch'])) {
                    foreach ($variant['instructions'] as $instruction) {
                        if (($instruction['branch'] ?? null) === $document['branch'] && ! empty($instruction['applies_if'])) {
                            $groups = $instruction['applies_if'];
                            array_push($branchGroups, ...(array_is_list($groups) ? $groups : [$groups]));
                        }
                    }
                    $branch = $branchGroups === [] ? ['status' => 'unknown', 'missing' => []] : $criteria->evaluate($branchGroups, $facts, $at);
                }
                $statuses = [$scope['status'], $condition['status'], $branch['status']];
                $applicability = in_array('unmet', $statuses, true) ? 'not_required'
                    : (in_array('unknown', $statuses, true) ? 'unknown'
                        : (($variant['kind'] === 'option' || ($document['optional'] ?? false)) ? 'conditional' : 'required'));
                $result[] = ['id' => $document['id'], 'label' => $document['label'], 'note' => $document['note'] ?? null,
                    'evidence_kind' => $document['evidence_kind'] ?? null, 'branch' => $document['branch'] ?? null,
                    'applicability' => $applicability, 'missing_facts' => array_values(array_unique([...$scope['missing'], ...$condition['missing'], ...$branch['missing']])),
                    'reason' => $branch['status'] === 'unknown' && $branchGroups === [] ? 'branch_not_determined' : 'reviewed_conditions',
                    'semantic_hash' => CatalogueHash::of([$document['semantic_hash'], $variant['conditions'], $variant['kind'], $branchGroups]),
                    'source_rule_id' => $variant['id'], 'source_hash' => $variant['source_hash'], 'review' => $review];
            }
        }

        return $result;
    }
}
