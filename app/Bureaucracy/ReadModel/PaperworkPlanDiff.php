<?php

namespace App\Bureaucracy\ReadModel;

/**
 * What changed in a person's plan between two reads of it (PlanReadModel::for, the
 * bureaucracy.plan.1 payload): a task added or no longer there, its state, a step
 * added, gone or changed, a date moved. Composer reads the plan, makes the change,
 * reads it again and says what this found, in the plan's own words: titles and
 * dates come only from the plan's card fields, never worked out here, so the AI
 * never writes a figure (Composer actions doc, 23 Oct 2026).
 *
 * Only open tasks are compared; a task that closed shows as its state changing.
 */
final class PaperworkPlanDiff
{
    /** The kinds of dated row a step carries that are its own (a document's expiry is a fact, not a step's date). */
    private const DATED = ['legal_due', 'preparation_target', 'authority_follow_up', 'appointment'];

    /**
     * @param  array<string, mixed>  $before  the plan before the change
     * @param  array<string, mixed>  $after  the plan after it
     * @return list<array{process_id: int|null, occurrence_key: string, title: string|null, change: string, step?: array{id: string, title: string|null}, from?: string|null, to?: string|null}>
     */
    public static function between(array $before, array $after): array
    {
        $old = self::byKey($before);
        $new = self::byKey($after);
        $changes = [];

        foreach ($new as $key => $process) {
            if (! isset($old[$key])) {
                $changes[] = self::row($process, 'added');

                continue;
            }
            $was = $old[$key];
            $from = $was['state']['workflow'] ?? null;
            $to = $process['state']['workflow'] ?? null;
            if ($from !== $to) {
                $changes[] = [...self::row($process, 'state_changed'), 'from' => $from, 'to' => $to];
            }
            array_push($changes, ...self::steps($was, $process));
        }

        foreach ($old as $key => $process) {
            if (! isset($new[$key])) {
                $changes[] = self::row($process, 'removed');
            }
        }

        return $changes;
    }

    /** @return array<string, array<string, mixed>> open tasks by their occurrence key */
    private static function byKey(array $plan): array
    {
        $processes = [];
        foreach ($plan['processes'] ?? [] as $process) {
            if (is_array($process) && is_string($process['occurrence_key'] ?? null) && ! ($process['is_closed'] ?? false)) {
                $processes[$process['occurrence_key']] = $process;
            }
        }

        return $processes;
    }

    /** @return list<array<string, mixed>> */
    private static function steps(array $was, array $process): array
    {
        $old = self::stepsById($was);
        $new = self::stepsById($process);
        $changes = [];

        foreach ($new as $id => $step) {
            if (! isset($old[$id])) {
                $changes[] = [...self::row($process, 'step_added'), 'step' => self::step($step)];

                continue;
            }
            $from = $old[$id]['status'] ?? null;
            $to = $step['status'] ?? null;
            if ($from !== $to) {
                $changes[] = [...self::row($process, 'state_changed'), 'step' => self::step($step), 'from' => $from, 'to' => $to];
            }
            foreach (self::dates($step) as $kind => $date) {
                $earlier = self::dates($old[$id])[$kind] ?? null;
                if ($earlier !== null && $earlier !== $date) {
                    $changes[] = [...self::row($process, 'due_moved'), 'step' => self::step($step), 'from' => $earlier, 'to' => $date];
                }
            }
        }

        foreach ($old as $id => $step) {
            if (! isset($new[$id])) {
                $changes[] = [...self::row($process, 'step_removed'), 'step' => self::step($step)];
            }
        }

        return $changes;
    }

    /** @return array<string, array<string, mixed>> */
    private static function stepsById(array $process): array
    {
        $steps = [];
        foreach ($process['steps'] ?? [] as $step) {
            if (is_array($step) && is_string($step['step_id'] ?? null)) {
                $steps[$step['step_id']] = $step;
            }
        }

        return $steps;
    }

    /** @return array<string, string> a step's own dated rows, by kind */
    private static function dates(array $step): array
    {
        $dates = [];
        foreach ($step['dates'] ?? [] as $row) {
            if (is_array($row) && in_array($row['kind'] ?? null, self::DATED, true) && is_string($row['date'] ?? null)) {
                $dates[$row['kind']] ??= $row['date'];
            }
        }

        return $dates;
    }

    /** @return array{process_id: int|null, occurrence_key: string, title: string|null, change: string} */
    private static function row(array $process, string $change): array
    {
        return ['process_id' => is_int($process['id'] ?? null) ? $process['id'] : null,
            'occurrence_key' => $process['occurrence_key'], 'title' => $process['title'] ?? null, 'change' => $change];
    }

    /** @return array{id: string, title: string|null} */
    private static function step(array $step): array
    {
        return ['id' => $step['step_id'], 'title' => $step['title'] ?? null];
    }
}
