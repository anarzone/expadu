<?php

namespace App\Bureaucracy\ReadModel;

use App\Bureaucracy\Assessment\AssessmentInput;

/**
 * Per-unit coverage of the active reviewed catalogue for one jurisdiction. A unit state describes
 * how far the reviewed content goes, never whether the person is eligible or every case is covered.
 */
final class CoverageUnits
{
    public function for(AssessmentInput $input): array
    {
        $today = $input->at->toDateString();
        $units = [];
        foreach ($input->catalogue['definitions'] ?? [] as $definition) {
            foreach ($definition['variants'] as $variant) {
                if ($variant['jurisdiction'] !== $input->jurisdiction) {
                    continue;
                }
                $review = $variant['review'] ?? [];
                $current = ($review['review_status'] ?? null) === 'approved' && ($review['review_due_at'] ?? '') >= $today
                    && ($review['effective_from'] ?? '0000-01-01') <= $today && ($review['effective_to'] ?? '9999-12-31') >= $today;
                $state = ! $current ? 'not_covered' : ($variant['coverage'] === 'complete' && ! empty($variant['coverage_review']['source_review_reference']) ? 'complete' : 'partial');
                $units[] = $this->unit($definition['id'], $variant['id'], $variant['title'], $review, array_column($variant['actions'] ?? [], 'url'), $state);
            }
        }
        $inventory = array_column($input->catalogue['inventory'] ?? [], null, 'key');
        foreach ($input->catalogue['withdrawn'] ?? [] as $key) {
            $record = $inventory[$key]['authored_record'] ?? [];
            if (($record['jurisdiction'] ?? null) !== $input->jurisdiction) {
                continue;
            }
            // Withdrawn units keep their identity and review metadata, but no guidance text or links.
            $units[] = $this->unit($inventory[$key]['process_id'] ?? null, $key, null, $record, [], 'withdrawn');
        }
        usort($units, fn ($a, $b) => strcmp((string) $a['definition_id'], (string) $b['definition_id']) ?: strcmp($a['unit_id'], $b['unit_id']));

        return $units;
    }

    private function unit(?string $definitionId, string $unitId, ?string $title, array $review, array $official, string $state): array
    {
        $legal = array_values(array_filter(array_map(fn ($source) => is_array($source) ? ($source['url'] ?? null) : null, $review['legal_sources'] ?? []), 'is_string'));

        return ['definition_id' => $definitionId, 'unit_id' => $unitId, 'title' => $title, 'content_version' => $review['content_version'] ?? null,
            'verified_at' => $review['verified_at'] ?? null, 'review_due_at' => $review['review_due_at'] ?? null,
            'source_urls' => ['official' => array_values($official), 'legal' => $legal], 'state' => $state];
    }
}
