<?php

namespace App\Composer;

use Carbon\CarbonImmutable;

/**
 * Moves a workspace saved before plans went straight in (approval, drafts,
 * versions) to the direct-apply shape. An unfinished draft is never applied:
 * it stays in the conversation as an earlier proposal the person can reapply.
 * Applied versions become changes, so their Undo keeps working.
 */
final class WorkspaceUpgrade
{
    public const SCHEMA = 2;

    /** A UUID-shaped id derived from content. */
    private static function stableId(array $content): string
    {
        $hash = hash('sha256', json_encode($content));

        return vsprintf('%s-%s-4%s-a%s-%s', [substr($hash, 0, 8), substr($hash, 8, 4), substr($hash, 13, 3), substr($hash, 17, 3), substr($hash, 20, 12)]);
    }

    public function toCurrent(array $state): array
    {
        if (($state['schema'] ?? 1) >= self::SCHEMA) {
            return $state;
        }

        $conversations = $state['conversations'] ?? [];
        foreach ($conversations as $i => $conversation) {
            foreach ($conversation['messages'] ?? [] as $j => $message) {
                if (($message['kind'] ?? null) === 'plan') {
                    // A plan card shows its day as it is now, not a stored copy.
                    $date = $message['plan']['date'] ?? null;
                    unset($message['plan']);
                    $conversations[$i]['messages'][$j] = [...$message, 'date' => $date];
                }
            }
        }

        $active = $state['active'] ?? ($conversations[0]['id'] ?? null);
        $draft = $state['draft'] ?? null;
        if (is_array($draft) && ! empty($draft['slots']) && isset($draft['date'])) {
            foreach ($conversations as $i => $conversation) {
                if ($conversation['id'] === $active) {
                    $weekday = CarbonImmutable::parse($draft['date'], 'Europe/Berlin')->isoFormat('dddd D MMMM');
                    // Derived from the draft, so reading a not-yet-saved upgrade twice names it the same.
                    $id = self::stableId($draft);
                    $conversations[$i]['messages'][] = [
                        'id' => $id, 'role' => 'assistant', 'created_at' => now()->toIso8601String(),
                        'text' => "You had an unfinished proposal for {$weekday}. It isn’t in your plans. Reapply it if you still want it.",
                        'kind' => 'earlier_draft', 'draft_id' => $id, 'date' => $draft['date'], 'plan' => $draft,
                        'reapplied_change' => null,
                    ];
                }
            }
        }

        $changes = array_map(fn (array $version): array => [
            'id' => $version['id'] ?? self::stableId($version),
            'date' => $version['date'],
            'label' => 'Saved earlier',
            'before' => $version['before'] ?? null,
            'after' => $version['after'] ?? null,
            'rows' => PlanChangeRows::between($version['before'] ?? null, $version['after'] ?? null),
            'created_at' => $version['created_at'] ?? null,
            'undone_at' => ($version['undone'] ?? false) ? ($version['created_at'] ?? '1970-01-01T00:00:00+00:00') : null,
        ], $state['versions'] ?? []);

        return [
            'schema' => self::SCHEMA,
            'active' => $active,
            'focus' => $state['focus'] ?? null,
            'plans' => $state['plans'] ?? [],
            'changes' => array_slice($changes, -WorkspaceStore::CHANGE_LIMIT),
            'conversations' => $conversations,
        ];
    }
}
