<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Catalogue\CatalogueHash;

final class ProcessPrerequisites
{
    /** Only current, unambiguous occurrences in this subject's authorised projection can satisfy a dependency. */
    public function for(array $proposals, array $processes): array
    {
        $stored = array_column($processes, null, 'occurrence_key');
        $candidates = [];
        foreach ($proposals as $proposal) {
            $process = $stored[$proposal['occurrence_key']] ?? null;
            $current = $process !== null
                && CatalogueHash::of($process['step_definitions']) === CatalogueHash::of($proposal['steps'])
                && $process['state']['workflow'] !== 'cancelled';
            foreach ($proposal['steps'] as $step) {
                $candidates[$step['id']][] = $current ? ($process['state']['steps'][$step['id']] ?? 'unknown') : 'unknown';
            }
        }
        $result = [];
        foreach ($candidates as $id => $states) {
            $result[$id] = count($states) === 1 ? $states[0] : 'unknown';
        }

        return $result;
    }
}
