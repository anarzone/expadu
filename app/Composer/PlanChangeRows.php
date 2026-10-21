<?php

namespace App\Composer;

/**
 * What a change did to a day's items, old → new: moved (new time), added,
 * removed, or one in place of another at the same time. Each reply carries
 * these rows, and the plan marks the items they name.
 */
final class PlanChangeRows
{
    /**
     * @return list<array{kind: string, id: string, title: string, type: string, from?: string, to?: string, was?: string}>
     */
    public static function between(?array $before, ?array $after): array
    {
        $was = collect($before['slots'] ?? [])->keyBy('id');
        $now = collect($after['slots'] ?? [])->keyBy('id');
        $rows = [];
        foreach ($now as $id => $slot) {
            $old = $was->get($id);
            if ($old === null) {
                $rows[] = ['kind' => 'added', ...self::item($slot), 'to' => self::span($slot)];
            } elseif (self::span($old) !== self::span($slot)) {
                $rows[] = ['kind' => 'moved', ...self::item($slot), 'from' => self::span($old), 'to' => self::span($slot)];
            }
        }
        foreach ($was as $id => $slot) {
            if ($now->has($id)) {
                continue;
            }
            $index = collect($rows)->search(fn (array $row): bool => $row['kind'] === 'added' && $row['to'] === self::span($slot));
            if ($index !== false) {
                $rows[$index] = [...$rows[$index], 'kind' => 'replaced', 'was' => (string) ($slot['name'] ?? '')];
            } else {
                $rows[] = ['kind' => 'removed', ...self::item($slot), 'from' => self::span($slot)];
            }
        }

        return array_values($rows);
    }

    /** @return array{id: string, title: string, type: string} */
    private static function item(array $slot): array
    {
        return ['id' => (string) $slot['id'], 'title' => (string) ($slot['name'] ?? ''), 'type' => (string) ($slot['type'] ?? '')];
    }

    /** "13:00–14:00", or just the start when the end isn't known or listed. */
    private static function span(array $slot): string
    {
        $start = (string) ($slot['start_time'] ?? '');
        $end = $slot['end_time'] ?? null;

        return is_string($end) && $end !== '' && ($slot['end_listed'] ?? true) ? "{$start}–{$end}" : $start;
    }
}
