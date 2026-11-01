<?php

namespace App\Bureaucracy\Processes;

use DomainException;

final class ProgressSummary
{
    /** Input already excludes unavailable/withdrawn steps at the read-model boundary. */
    public function for(array $processes): array
    {
        $sets = ['todo' => [], 'blocked' => [], 'waiting' => [], 'completed' => []];
        $seen = [];
        foreach ($processes as $process) {
            $state = $process['state'];
            if ($state['workflow'] === 'cancelled') {
                continue;
            }
            foreach ($state['steps'] as $step => $status) {
                $id = $process['id'].':'.$step;
                if (isset($seen[$id])) {
                    throw new DomainException('Progress input contains a duplicate step occurrence.');
                }
                $seen[$id] = true;
                $bucket = match ($status) {
                    'completed' => 'completed',
                    'blocked' => 'blocked',
                    'todo' => in_array($state['workflow'], ['submitted', 'waiting_authority'], true) ? 'waiting' : 'todo',
                    default => throw new DomainException('Unknown step state.'),
                };
                $sets[$bucket][] = $id;
            }
        }
        $result = ['total' => count($seen)];
        foreach ($sets as $bucket => $ids) {
            $result[$bucket] = ['count' => count($ids), 'ids' => $ids];
        }

        return $result;
    }
}
