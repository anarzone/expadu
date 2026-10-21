<?php

namespace App\Home;

use App\Bureaucracy\ReadModel\AccountHolderPlan;
use App\Bureaucracy\ReadModel\PlanAttention;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What Today shows from the person's Paperwork, read from the reviewed v2
 * plan (AccountHolderPlan, with its publication, city, consent and fact
 * checks) and never from legacy task rows: the deadline strip's one urgent
 * dated row, the next step for the Paperwork panel, and how many of each
 * process's required documents are confirmed ready. Titles are the
 * reviewed guidance's own; nothing here is a legal claim of its own.
 */
final class TodayPaperwork
{
    /** Which dated row leads when several fall on the same day. */
    private const LEAD = ['document_expiry', 'legal_due', 'authority_follow_up', 'preparation_target'];

    public function __construct(private AccountHolderPlan $plans, private PlanAttention $attention) {}

    /** @return array{strip: array<string, mixed>|null, processes: array<int, array<string, mixed>>, focus: array<string, mixed>|null}|null */
    public function for(User $user): ?array
    {
        try {
            $plan = $this->plans->for($user);
        } catch (AuthorizationException) {
            return null;
        }

        if ($plan === null) {
            return null;
        }

        $processes = [];
        foreach ($plan['processes'] as $process) {
            if ($process['id'] !== null) {
                $processes[$process['id']] = $process;
            }
        }
        $next = $plan['overview']['next_actions'][0] ?? null;

        return [
            'strip' => $this->strip($plan, $processes, $next),
            // What to bring, for an appointment the day lists from Paperwork.
            'processes' => collect($processes)->map(fn (array $process) => [
                'title' => $process['title'] ?? null,
                'documents' => $this->documents($process),
                'occurrence_key' => $process['occurrence_key'] ?? null,
            ])->filter(fn (array $process) => $process['documents'] !== null)->all(),
            'focus' => $next === null ? null : [
                'process_title' => $next['process_title'] ?? null,
                'title' => $next['title'],
                'documents' => $this->documents($processes[$next['process_id'] ?? 0] ?? null),
                'link' => ['process' => $next['occurrence_key'] ?? null, 'step' => $next['id'] ?? null],
            ],
        ];
    }

    /** The urgent dated row (overdue, or due within three days) that leads, with the next step. */
    private function strip(array $plan, array $processes, ?array $next): ?array
    {
        $rows = array_values(array_filter(
            $this->attention->for($plan),
            fn (array $row) => in_array($row['kind'], self::LEAD, true) && in_array($row['urgency'], ['overdue', 'critical'], true),
        ));
        if ($rows === []) {
            return null;
        }
        $soonest = min(array_column($rows, 'days_remaining'));
        $rows = array_values(array_filter($rows, fn (array $row) => $row['days_remaining'] === $soonest));
        usort($rows, fn (array $a, array $b) => array_search($a['kind'], self::LEAD, true) <=> array_search($b['kind'], self::LEAD, true));
        $row = $rows[0];
        $event = collect($plan['timeline'])->firstWhere('id', $row['id']);
        $processId = $row['process_id'] ?? ($next['process_id'] ?? null);

        return [
            'kind' => $row['kind'],
            // An expiry names the recorded document (visa, residence permit…).
            'document' => $event['document'] ?? null,
            'title' => $row['title'],
            'date' => substr((string) $row['date'], 0, 10),
            'days_remaining' => $row['days_remaining'],
            'urgency' => $row['urgency'],
            'next' => $next['title'] ?? null,
            'documents' => $this->documents($processes[$processId ?? 0] ?? null),
            'link' => [
                'process' => $row['occurrence_key'] ?? ($next['occurrence_key'] ?? null),
                'step' => $next !== null && ($next['process_id'] ?? null) === $processId ? ($next['id'] ?? null) : null,
            ],
        ];
    }

    /** Required documents confirmed ready for a process, across its steps; null without any. */
    private function documents(?array $process): ?array
    {
        if ($process === null) {
            return null;
        }
        $ready = 0;
        $total = 0;
        foreach ($process['steps'] ?? [] as $step) {
            $ready += $step['requirements']['ready'] ?? 0;
            $total += $step['requirements']['total'] ?? 0;
        }

        return $total === 0 ? null : ['ready' => $ready, 'total' => $total];
    }
}
