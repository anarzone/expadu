<?php

namespace App\Composer;

use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Models\User;

/**
 * What Composer's Your day and its Paperwork starter say about the person's own record:
 * the nearest dated row coming up (a document's expiry, a date to aim for) and, for their
 * next appointment, its task and how many of its documents they marked ready. Everything
 * is the v2 plan's own (its dates, titles and readiness); nothing here is a legal claim.
 */
final class PaperworkGlance
{
    public function __construct(private AccountPlanEntry $entry) {}

    /**
     * @return array{deadline: array<string, mixed>|null, appointment: array<string, mixed>|null}
     */
    public function for(User $user): array
    {
        $plan = $this->entry->for($user)['plan'] ?? null;

        if (! is_array($plan)) {
            return ['deadline' => null, 'appointment' => null];
        }

        $rows = collect($plan['coming_up'] ?? []);
        $processes = collect($plan['processes'] ?? []);
        $process = fn (array $row): ?array => $processes->first(fn (array $p): bool => ($row['process_id'] ?? null) !== null
            ? $p['id'] === $row['process_id']
            : $p['occurrence_key'] === ($row['occurrence_key'] ?? null));

        // The nearest dated row; on one date, a document's own expiry (your record) comes
        // before the targets that lead up to it, as Paperwork's Coming up folds them.
        $dated = $rows->filter(fn (array $row): bool => $row['kind'] !== 'appointment' && $row['date'] !== null)
            ->sortBy(fn (array $row): string => substr((string) $row['date'], 0, 10).($row['kind'] === 'document_expiry' ? '0' : '1'));
        $deadline = $dated->first();
        // An expiry belongs to no task of its own; it opens the task that leads up to it that day.
        $deadlineTask = $deadline === null ? null : ($process($deadline) ?? $dated->map($process)->filter()
            ->first(fn (array $task, $key): bool => substr((string) $dated[$key]['date'], 0, 10) === substr((string) $deadline['date'], 0, 10)));
        $appointment = $rows->first(fn (array $row): bool => $row['kind'] === 'appointment' && $row['date'] !== null);
        $requirements = collect($plan['paperwork']['requirements'] ?? []);

        return [
            'deadline' => $deadline === null ? null : [
                'kind' => $deadline['kind'],
                'document' => $deadline['document'] ?? null,
                'date' => substr((string) $deadline['date'], 0, 10),
                'days_remaining' => $deadline['days_remaining'],
                'process' => $deadlineTask['title'] ?? null,
                'occurrence_key' => $deadlineTask['occurrence_key'] ?? null,
            ],
            'appointment' => $appointment === null ? null : (function () use ($appointment, $process, $requirements): array {
                $task = $process($appointment);
                $documents = $task === null || $task['id'] === null ? collect()
                    : $requirements->filter(fn (array $r): bool => $r['process_id'] === $task['id'] && $r['applicability'] === 'required');

                return [
                    'starts_at' => $appointment['starts_at'] ?? $appointment['date'],
                    'process' => $task['title'] ?? null,
                    'occurrence_key' => $task['occurrence_key'] ?? null,
                    'documents' => ['ready' => $documents->where('readiness', 'confirmed_for_use')->count(), 'total' => $documents->count()],
                ];
            })(),
        ];
    }
}
