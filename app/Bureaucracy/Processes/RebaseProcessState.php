<?php

namespace App\Bureaucracy\Processes;

use App\Bureaucracy\Catalogue\CatalogueHash;

final class RebaseProcessState
{
    public function for(array $state, array $oldSteps, array $newSteps): array
    {
        $old = array_column($oldSteps, null, 'id');
        $rebased = (new ProcessStateMachine)->initial($newSteps);
        foreach ($newSteps as $step) {
            if (isset($old[$step['id']]) && CatalogueHash::of($old[$step['id']]) === CatalogueHash::of($step)
                && ($state['steps'][$step['id']] ?? null) === 'completed') {
                $rebased['steps'][$step['id']] = 'completed';
            }
        }
        foreach ($newSteps as $step) {
            if ($rebased['steps'][$step['id']] !== 'completed') {
                $blocked = array_filter($step['depends_on'], fn ($id) => ($rebased['steps'][$id] ?? null) !== 'completed');
                $rebased['steps'][$step['id']] = $blocked === [] ? 'todo' : 'blocked';
            }
        }
        $rebased['workflow'] = $state['workflow'];
        $rebased['completion_basis'] = $state['completion_basis'];
        if ($state['workflow'] === 'completed' && array_filter($rebased['steps'], fn ($status) => $status !== 'completed') !== []) {
            $rebased['workflow'] = 'preparing';
            $rebased['completion_basis'] = null;
        }

        return $rebased;
    }
}
