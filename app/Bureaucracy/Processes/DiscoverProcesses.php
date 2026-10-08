<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Assessment\AssessmentInput;
use App\Bureaucracy\Assessment\AssessPerson;
use App\Bureaucracy\Catalogue\CatalogueHash;

final class DiscoverProcesses
{
    /** Pure proposals. A read does not create process records or completion state. */
    public function for(AssessmentInput $input): array
    {
        $assessment = (new AssessPerson)->assess($input)->toArray();
        $proposals = [];
        foreach ($assessment['processes'] as $process) {
            foreach ($process['variants'] as $variant) {
                if (! $variant['actionable']) {
                    continue;
                }
                $anchor = $variant['occurrence_fact'] ?? null;
                $context = $anchor === null ? 'lifetime' : ($input->facts['evidence'][$anchor]['context_id'] ?? 'unbound:'.$anchor);
                $key = CatalogueHash::of([$process['definition_id'], $input->jurisdiction, $context]);
                $proposals[$key] ??= ['definition_id' => $process['definition_id'], 'topic' => $process['topic'],
                    'jurisdiction' => $input->jurisdiction, 'occurrence_key' => $key, 'context_id' => $context, 'occurrence_fact' => $anchor,
                    'catalogue_hash' => $input->catalogue['release_hash'], 'steps' => [], 'variants' => []];
                $proposals[$key]['steps'][$variant['step_id']] = ['id' => $variant['step_id'],
                    'semantic_hash' => $variant['source_hash'],
                    'depends_on' => $variant['step_dependencies'] ?? array_map(fn ($taskKey) => $taskKey.'.complete', $variant['depends_on'])];
                $proposals[$key]['variants'][] = $variant;
            }
        }
        foreach ($proposals as &$proposal) {
            ksort($proposal['steps']);
            $proposal['steps'] = array_values($proposal['steps']);
            $proposal['review_token'] = CatalogueHash::of([$assessment['input_revision'], $proposal]);
        }
        unset($proposal);
        ksort($proposals);

        return array_values($proposals);
    }
}
