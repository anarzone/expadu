<?php

namespace App\Bureaucracy\Processes;

use DomainException;

final class ProcessHistory
{
    /** Superseded reports stay in storage; their latest correction occupies the original timeline slot. */
    public function active(array $events): array
    {
        usort($events, fn ($one, $two) => $one['id'] <=> $two['id']);
        $roots = [];
        $active = [];
        foreach ($events as $event) {
            $id = $event['id'];
            $target = $event['corrects_event_id'] ?? null;
            $root = $target === null ? $id : ($roots[$target] ?? null);
            if ($root === null || ($target !== null && ($active[$root]['revision_event_id'] !== $target || $active[$root]['type'] !== $event['type']))) {
                throw new DomainException('Invalid process correction chain.');
            }
            $roots[$id] = $root;
            $active[$root] = [...$event, 'id' => $root, 'revision_event_id' => $id];
        }
        ksort($active);

        return array_values($active);
    }
}
