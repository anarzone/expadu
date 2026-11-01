<?php

namespace App\Bureaucracy\Processes;

use App\Models\BureaucracyEvidenceShare;
use App\Models\BureaucracyRequirementUse;

/**
 * When "Track task" can still be undone: nothing has happened since the start. One rule for
 * the process_untracked command and the plan's `untrackable` flag, so they never disagree.
 */
final class UntrackEligibility
{
    /** @param list<string> $eventTypes every stored event type of the process */
    public static function allows(array $state, array $eventTypes, bool $hasRecords): bool
    {
        return ($state['workflow'] ?? null) === 'not_started'
            && ! in_array('completed', $state['steps'] ?? [], true)
            && ! $hasRecords
            && collect($eventTypes)->every(fn ($type) => in_array($type, ['process_started', 'process_untracked'], true));
    }

    /** @param list<int> $processIds @return array<int, true> processes holding requirement uses or evidence shares */
    public static function withRecords(array $processIds): array
    {
        if ($processIds === []) {
            return [];
        }
        $ids = BureaucracyRequirementUse::query()->whereIn('process_id', $processIds)->distinct()->pluck('process_id')
            ->merge(BureaucracyEvidenceShare::query()->whereIn('process_id', $processIds)->distinct()->pluck('process_id'));

        return array_fill_keys($ids->map(fn ($id) => (int) $id)->unique()->all(), true);
    }
}
